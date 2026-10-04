<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/cors.php";
require_once __DIR__ . "/../database/conexao.php";
require_once __DIR__ . "/../Authentication/Authentication.php";
require_once __DIR__ . "/../service/aporteRecorrenteService.php";

function responderRecorrente(array $corpo, $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responderRecorrente(["sucesso" => false, "mensagem" => "Método não permitido. Use POST."], 405);
}

$idUsuario = autenticar();

$dados = json_decode(file_get_contents("php://input"), true);

if (!is_array($dados)) {
    responderRecorrente(["sucesso" => false, "mensagem" => "Corpo inválido: envie um JSON."], 400);
}

try {

    $acao = $dados["acao"] ?? null;

    switch ($acao) {

        case "listar":

            $idCarteira = $dados["id_carteira"] ?? null;   // opcional: sem ele, traz de todas as carteiras do usuário

            if ($idCarteira !== null && $idCarteira !== "" && !carteiraPertenceAoUsuario($conexao, $idCarteira, $idUsuario)) {
                responderRecorrente(["sucesso" => false, "mensagem" => "A carteira não pertence ao usuário"], 403);
            }

            $aportes = listarAporteRecorrente($conexao, $idUsuario, $idCarteira);

            responderRecorrente(["sucesso" => true, "quantidade" => count($aportes), "aportes" => $aportes]);

        case "alternar":

            $id = $dados["id_aporte_recorrente"] ?? null;
            $ligar = $dados["ativo"] ?? null;   // true = ligar, false = desligar

            if (!$id || !is_bool($ligar)) {
                responderRecorrente(["sucesso" => false, "mensagem" => "Envie id_aporte_recorrente e ativo (true/false)."], 400);
            }

            $regra = buscarAporteRecorrenteDoUsuario($conexao, $id, $idUsuario);

            if (!$regra) {
                responderRecorrente(["sucesso" => false, "mensagem" => "Aporte recorrente não encontrado"], 404);
            }

            $proxima = alternarAporteRecorrente($conexao, $regra, $ligar);

            responderRecorrente([
                "sucesso" => true,
                "mensagem" => $ligar ? "Aporte recorrente ligado" : "Aporte recorrente desligado",
                "ativo" => $ligar,
                "proxima_execucao" => $proxima
            ]);

        case "atualizar":

            $id = $dados["id_aporte_recorrente"] ?? null;
            $novoValor = $dados["valor_recorrente"] ?? null;
            $novaFrequencia = $dados["frequencia_recorrente"] ?? null;

            if (!$id || ($novoValor === null && $novaFrequencia === null)) {
                responderRecorrente(["sucesso" => false, "mensagem" => "Envie id_aporte_recorrente e valor_recorrente e/ou frequencia_recorrente."], 400);
            }

            if ($novoValor !== null && (!is_numeric($novoValor) || (float) $novoValor <= 0)) {
                responderRecorrente(["sucesso" => false, "mensagem" => "valor_recorrente deve ser maior que zero."], 400);
            }

            if ($novaFrequencia !== null && !in_array($novaFrequencia, FREQUENCIAS_RECORRENTE, true)) {
                responderRecorrente(["sucesso" => false, "mensagem" => "frequencia_recorrente inválida. Use: " . implode(", ", FREQUENCIAS_RECORRENTE) . "."], 400);
            }

            $regra = buscarAporteRecorrenteDoUsuario($conexao, $id, $idUsuario);

            if (!$regra) {
                responderRecorrente(["sucesso" => false, "mensagem" => "Aporte recorrente não encontrado"], 404);
            }

            $proxima = atualizarAporteRecorrente(
                $conexao,
                $regra,
                $novoValor === null ? null : round((float) $novoValor, 2),
                $novaFrequencia
            );

            responderRecorrente([
                "sucesso" => true,
                "mensagem" => "Aporte recorrente atualizado com sucesso",
                "proxima_execucao" => $proxima
            ]);

        default:

            responderRecorrente([
                "sucesso" => false,
                "mensagem" => "Ação inválida. Use listar, alternar ou atualizar. (Para CRIAR um recorrente, envie o aporte em user/aportes.php com recorrencia_aporte diferente de Único.)"
            ], 400);
    }

} catch (Throwable $erro) {

    error_log("aporteRecorrente.php: " . $erro->getMessage());

    responderRecorrente(["sucesso" => false, "mensagem" => "Erro interno ao processar o aporte recorrente."], 500);
}
?>