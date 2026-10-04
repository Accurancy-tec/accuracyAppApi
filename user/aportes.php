<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/cors.php";
require_once __DIR__ . "/../database/conexao.php";
require_once __DIR__ . "/../Authentication/Authentication.php";
require_once __DIR__ . "/../service/ativoService.php";
require_once __DIR__ . "/../service/cotacaoService.php";
require_once __DIR__ . "/../service/posicaoService.php";
require_once __DIR__ . "/../service/carteiraService.php";
require_once __DIR__ . "/../service/aporteRecorrenteService.php";   // já traz o automacaoCronService.php

const TIPOS_APORTE = ["Compra", "Venda", "Dividendo"];
const RECORRENCIAS_APORTE = ["Único", "Diário", "Semanal", "Mensal", "Anual"];
const CATEGORIAS_ATIVO = ["Ações", "Cripto", "Renda Fixa", "FIIs", "Internacional"];

// Erro "esperado" (dado inválido, saldo insuficiente...). Volta como HTTP 200 + sucesso:false
// para o Toast do app mostrar a mensagem. Erro inesperado (banco caiu etc.) volta como HTTP 500.
class RegraNegocioException extends Exception
{
}

function responderAporte(array $corpo, $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

// Aceita número ou texto numérico ("350.5"). Qualquer outra coisa vira null.
function numeroOuNull($valor)
{
    if (is_int($valor) || is_float($valor)) {
        return (float) $valor;
    }

    if (is_string($valor) && is_numeric(trim($valor))) {
        return (float) trim($valor);
    }

    return null;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responderAporte(["sucesso" => false, "mensagem" => "Método não permitido. Use POST."], 405);
}

$idUsuario = autenticar();   // sem token válido ele mesmo responde 401 e encerra

$dados = json_decode(file_get_contents("php://input"), true);

if (!is_array($dados)) {
    responderAporte(["sucesso" => false, "mensagem" => "Corpo inválido: envie um JSON."], 400);
}

try {

    // ---------- 1) lê e valida o que o app mandou ----------
    $simbolo = strtoupper(trim((string) ($dados["ativo_aporte"] ?? "")));
    $nomeAtivo = trim((string) ($dados["name_ativo"] ?? ""));
    $categoria = trim((string) ($dados["categoria_ativo"] ?? ""));
    $tipo = $dados["tipo_aporte"] ?? "Compra";
    $recorrencia = $dados["recorrencia_aporte"] ?? "Único";
    $observacao = trim((string) ($dados["observacao_aporte"] ?? ""));
    $valor = numeroOuNull($dados["valor_aporte"] ?? null);
    $quantidade = numeroOuNull($dados["quantidade_aporte"] ?? null);
    $idCarteiraInformada = $dados["id_carteira"] ?? null;   // opcional

    if ($simbolo === "" || strlen($simbolo) > 15) {
        throw new RegraNegocioException("Informe o ativo (ativo_aporte).");
    }

    if ($nomeAtivo === "") {
        $nomeAtivo = $simbolo;
    }

    if (!in_array($categoria, CATEGORIAS_ATIVO, true)) {
        throw new RegraNegocioException("Categoria inválida. Use: " . implode(", ", CATEGORIAS_ATIVO) . ".");
    }

    if (!in_array($tipo, TIPOS_APORTE, true)) {
        throw new RegraNegocioException("Tipo inválido. Use: " . implode(", ", TIPOS_APORTE) . ".");
    }

    if (!in_array($recorrencia, RECORRENCIAS_APORTE, true)) {
        throw new RegraNegocioException("Recorrência inválida. Use: " . implode(", ", RECORRENCIAS_APORTE) . ".");
    }

    if ($valor === null || $valor <= 0) {
        throw new RegraNegocioException("O valor do aporte (valor_aporte, em R$) deve ser maior que zero.");
    }

    if ($quantidade !== null && $quantidade <= 0) {
        $quantidade = null;   // 0 ou negativo = "não informou"
    }

    if ($recorrencia !== "Único" && $tipo !== "Compra") {
        throw new RegraNegocioException("Só aportes de Compra podem ser recorrentes.");
    }

    if (strlen($observacao) > 255) {
        $observacao = substr($observacao, 0, 255);
    }

    $valor = round($valor, 2);
    $hoje = dataHojeCron();   // dia em Brasília (o date() do servidor usa UTC)

    $conexao->beginTransaction();

    // ---------- 2) carteira: sempre do usuário logado (nunca um id fixo) ----------
    $idCarteira = resolverCarteiraDoUsuario($conexao, $idUsuario, $idCarteiraInformada);

    if ($idCarteira === null) {
        throw new RegraNegocioException("A carteira informada não pertence ao usuário.");
    }

    // ---------- 3) ativo (cria se não existir) ----------
    $idAtivo = buscarOuCriarAtivo($conexao, $simbolo, $nomeAtivo, $categoria);

    $cotacaoAtual = buscarUltimaCotacao($conexao, $idAtivo);

    // ---------- 4) quantidade ----------
    $quantidadeCalculada = false;

    if ($tipo === "Dividendo") {
        $quantidade = null;   // dividendo não tem cota
    } elseif ($quantidade === null) {

        $preco = $cotacaoAtual ? (float) $cotacaoAtual["preco_fechamento_cotacao"] : 0.0;

        if ($preco <= 0) {
            $stmtAuto = $conexao->prepare("SELECT cotacao_automatica_ativo::int FROM ativo WHERE id_ativo = ?");
            $stmtAuto->execute([$idAtivo]);

            if ((int) $stmtAuto->fetchColumn() === 1) {
                try {
                    $precos = buscarPrecosBrapiCron([$simbolo]);
                    $preco = $precos[$simbolo] ?? 0.0;

                    if ($preco > 0) {
                        salvarCotacao($conexao, $idAtivo, $hoje, $preco);
                    }
                } catch (Throwable $erroBrapi) {
                    error_log("aportes.php: Brapi indisponível para " . $simbolo . ": " . $erroBrapi->getMessage());
                }
            }
        }

        if ($preco <= 0) {
            throw new RegraNegocioException("Informe a quantidade (quantidade_aporte): ainda não existe cotação de " . $simbolo . " para calcular.");
        }

        $quantidade = round($valor / $preco, 8);
        $quantidadeCalculada = true;

        if ($quantidade <= 0) {
            throw new RegraNegocioException("Valor muito baixo para este ativo.");
        }
    }

    if ($cotacaoAtual === null && !$quantidadeCalculada && $quantidade !== null) {
        salvarCotacao($conexao, $idAtivo, $hoje, round($valor / $quantidade, 4));
    }

    // ---------- 5) grava o aporte ----------
    $insert = $conexao->prepare(
        "INSERT INTO aporte
            (id_carteira, id_ativo, tipo_aporte, quantidade_aporte, valor_aporte, recorrencia_aporte, observacao_aporte, data_aporte)
         VALUES
            (:carteira, :ativo, :tipo, :quantidade, :valor, :recorrencia, :observacao, :data)
         RETURNING id_aporte"
    );
    $insert->execute([
        ":carteira" => $idCarteira,
        ":ativo" => $idAtivo,
        ":tipo" => $tipo,
        ":quantidade" => $quantidade,
        ":valor" => $valor,
        ":recorrencia" => $recorrencia,
        ":observacao" => $observacao === "" ? null : $observacao,
        ":data" => $hoje
    ]);
    $idAporte = (int) $insert->fetchColumn();

    // ---------- 6) atualiza a posição (item_investimento) ----------
    if (!atualizarPosicao($conexao, $idCarteira, $idAtivo, $tipo, $quantidade, $valor)) {
        throw new RegraNegocioException("Quantidade insuficiente para venda. A operação não pode ser concluída.");
    }

    // ---------- 7) recorrente: cria a regra aqui mesmo ----------
    $idRecorrente = null;
    $proximaExecucao = null;
    $recorrenteJaExistia = false;

    if ($recorrencia !== "Único") {

        $existente = buscarRecorrenteAtivoIgual($conexao, $idCarteira, $idAtivo, $recorrencia);

        if ($existente) {
            // já tem uma regra ativa igual: só atualiza o valor, não cria outra
            $recorrenteJaExistia = true;
            $idRecorrente = (int) $existente["id_aporte_recorrente"];
            $proximaExecucao = $existente["proxima_execucao_recorrente"];

            $upd = $conexao->prepare("UPDATE aporte_recorrente SET valor_recorrente = ? WHERE id_aporte_recorrente = ?");
            $upd->execute([$valor, $idRecorrente]);
        } else {
            $dia = diaReferenciaRecorrente($recorrencia, $hoje);
            $proximaExecucao = proximaDataRecorrenteCron($hoje, $recorrencia, $dia);
            $idRecorrente = (int) criarAporteRecorrente($conexao, $idCarteira, $idAtivo, $valor, $recorrencia, $dia, $proximaExecucao);
        }
    }

    $conexao->commit();

    $mensagem = "Aporte cadastrado com sucesso";

    if ($idRecorrente !== null) {
        $mensagem .= $recorrenteJaExistia
            ? ". Você já tinha esse aporte recorrente ativo; o valor dele foi atualizado."
            : ". Próximo aporte automático em " . (new DateTimeImmutable($proximaExecucao))->format("d/m/Y") . ".";
    }

    responderAporte([
        "sucesso" => true,
        "mensagem" => $mensagem,
        "id_aporte" => $idAporte,
        "id_carteira" => $idCarteira,
        "quantidade_aporte" => $quantidade,
        "id_aporte_recorrente" => $idRecorrente,
        "proxima_execucao" => $proximaExecucao
    ]);

} catch (RegraNegocioException $erro) {

    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }

    responderAporte(["sucesso" => false, "mensagem" => $erro->getMessage()]);

} catch (Throwable $erro) {

    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }

    error_log("aportes.php: " . $erro->getMessage());

    responderAporte(["sucesso" => false, "mensagem" => "Erro ao salvar o aporte. Tente novamente."], 500);
}
?>