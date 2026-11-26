<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../../config/cors.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/CarteiraService.php";
require_once __DIR__ . "/../Services/PosicaoService.php";
require_once __DIR__ . "/../Services/PatrimonioService.php";
require_once __DIR__ . "/../Services/NotificacaoService.php";

const TIPOS_CARTEIRA = ["Real", "Simulada"];
const ESTILOS_CARTEIRA = ["Renda Fixa", "Renda Variavel", "Cripto", "Internacional", "Mista"];
const VALOR_MAXIMO_DEPOSITO = 10000000;

function responderCarteira(int $status, array $corpo): void
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function lerCorpoJson(): array
{
    $dados = json_decode(file_get_contents("php://input"), true);

    return is_array($dados) ? $dados : [];
}

/** Valor monetário positivo com até 2 casas ("1500", "1500.5", 1500.55). Devolve null se inválido. */
function valorMonetarioValido($valor): ?float
{
    $texto = trim((string) $valor);

    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $texto)) {
        return null;
    }

    $numero = (float) $texto;

    return $numero > 0 && $numero <= VALOR_MAXIMO_DEPOSITO ? round($numero, 2) : null;
}

// POST /carteiras/criar-carteira
// corpo: nome_carteira, tipo_carteira (Real|Simulada), estilo_carteira, meta_carteira,
//        saldo_inicial (obrigatório na simulada: dinheiro fictício só dessa carteira)
function criarCarteira()
{
    global $conexao;

    $idUsuario = (int) autenticar();
    $dados = lerCorpoJson();

    $nome = preg_replace("/\s+/u", " ", trim((string) ($dados["nome_carteira"] ?? "")));
    $tipo = (string) ($dados["tipo_carteira"] ?? "");
    $estilo = (string) ($dados["estilo_carteira"] ?? "Renda Variavel");
    $metaInformada = $dados["meta_carteira"] ?? 0;
    $meta = is_numeric($metaInformada) ? round((float) $metaInformada, 2) : -1;

    if ($nome === "" || mb_strlen($nome, "UTF-8") > 60) {
        responderCarteira(400, ["success" => false, "message" => "Dê um nome de até 60 caracteres para a carteira."]);
        return;
    }

    if (!in_array($tipo, TIPOS_CARTEIRA, true)) {
        responderCarteira(400, ["success" => false, "message" => "Escolha se a carteira é real ou simulada."]);
        return;
    }

    if (!in_array($estilo, ESTILOS_CARTEIRA, true)) {
        responderCarteira(400, ["success" => false, "message" => "Estilo de carteira inválido."]);
        return;
    }

    if ($meta < 0 || $meta > 1000000000) {
        responderCarteira(400, ["success" => false, "message" => "Informe uma meta válida (ou deixe em branco)."]);
        return;
    }

    $saldoInicial = null;
    if ($tipo === "Simulada") {
        $saldoInicial = valorMonetarioValido(
            is_numeric($dados["saldo_inicial"] ?? null) ? number_format((float) $dados["saldo_inicial"], 2, ".", "") : ""
        );

        if ($saldoInicial === null) {
            responderCarteira(400, [
                "success" => false,
                "message" => "Informe o saldo inicial da simulação (até R$ 10.000.000,00)."
            ]);
            return;
        }
    }

    try {
        $conexao->beginTransaction();

        $idCarteira = criarCarteiraService($conexao, $idUsuario, $nome, $estilo, $meta, $tipo);

        if ($saldoInicial !== null) {
            depositarNaCarteiraSimulada($conexao, $idUsuario, (int) $idCarteira, $saldoInicial, "Saldo inicial da simulação");
        }

        $conexao->commit();

        responderCarteira(200, [
            "success" => true,
            "message" => $tipo === "Simulada" ? "Carteira simulada criada" : "Carteira criada com sucesso",
            "id_carteira" => (int) $idCarteira
        ]);
    } catch (Throwable $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }

        error_log("Erro ao criar carteira: " . $erro->getMessage());
        responderCarteira(500, ["success" => false, "message" => "Não foi possível criar a carteira agora."]);
    }
}

// GET /carteiras/buscar-carteiras
// Lista as carteiras já com patrimônio, rentabilidade e posições calculados pela API.
function buscarCarteiras()
{
    global $conexao;
    $idUsuario = (int) autenticar();

    try {
        $patrimonio = montarPatrimonioUsuario($conexao, $idUsuario);

        responderCarteira(200, [
            "success" => true,
            "saldo_geral" => $patrimonio["saldo_geral"],
            "carteiras" => $patrimonio["carteiras"]
        ]);
    } catch (Throwable $erro) {
        error_log("Erro ao buscar carteiras: " . $erro->getMessage());
        responderCarteira(500, ["success" => false, "message" => "Não foi possível buscar as carteiras."]);
    }
}

// GET /carteiras/resumo[?id_carteira=]
// Números do dashboard: saldo geral, patrimônio, rentabilidade, distribuição e carteiras.
function resumoPatrimonio()
{
    global $conexao;
    $idUsuario = (int) autenticar();

    $idCarteira = isset($_GET["id_carteira"]) ? filter_var($_GET["id_carteira"], FILTER_VALIDATE_INT) : null;

    if ($idCarteira === false || ($idCarteira !== null && !carteiraPertenceAoUsuarioService($conexao, $idCarteira, $idUsuario))) {
        responderCarteira(404, ["success" => false, "message" => "Carteira não encontrada."]);
        return;
    }

    try {
        $patrimonio = montarPatrimonioUsuario($conexao, $idUsuario, ["id_carteira" => $idCarteira]);
        verificarMetasAlcancadas($conexao, $idUsuario, $patrimonio["carteiras"]);

        responderCarteira(200, ["success" => true] + $patrimonio);
    } catch (Throwable $erro) {
        error_log("Erro ao montar o resumo do patrimônio: " . $erro->getMessage());
        responderCarteira(500, ["success" => false, "message" => "Não foi possível calcular o patrimônio agora."]);
    }
}

// GET /carteiras/buscar-posicoes?id_carteira=
function itemInvestimento()
{
    global $conexao;
    $idUsuario = (int) autenticar();

    $idCarteira = filter_var($_GET["id_carteira"] ?? null, FILTER_VALIDATE_INT);

    if (!$idCarteira || !carteiraPertenceAoUsuarioService($conexao, $idCarteira, $idUsuario)) {
        responderCarteira(404, ["sucesso" => false, "mensagem" => "Carteira não encontrada."]);
        return;
    }

    try {
        responderCarteira(200, [
            "sucesso" => true,
            "posicoes" => buscarPosicoesService($conexao, $idCarteira)
        ]);
    } catch (Throwable $erro) {
        error_log("Erro ao buscar posições: " . $erro->getMessage());
        responderCarteira(500, ["sucesso" => false, "mensagem" => "Erro ao buscar posições."]);
    }
}

// GET /carteiras/distribuicao[?id_carteira=]
// Sem carteira: distribuição das carteiras reais (as simuladas ficam fora do patrimônio).
function distribuicaoCarteira()
{
    global $conexao;
    $idUsuario = (int) autenticar();

    $idCarteira = isset($_GET["id_carteira"]) ? filter_var($_GET["id_carteira"], FILTER_VALIDATE_INT) : null;

    if ($idCarteira === false || ($idCarteira !== null && !carteiraPertenceAoUsuarioService($conexao, $idCarteira, $idUsuario))) {
        responderCarteira(403, ["sucesso" => false, "mensagem" => "A carteira não pertence ao usuário", "distribuicao" => []]);
        return;
    }

    try {
        $patrimonio = montarPatrimonioUsuario($conexao, $idUsuario, ["id_carteira" => $idCarteira]);
        $distribuicao = $patrimonio["distribuicao"];

        responderCarteira(200, [
            "sucesso" => true,
            "distribuicao" => $distribuicao,
            "total" => round(array_sum(array_column($distribuicao, "valor")), 2)
        ]);
    } catch (Throwable $erro) {
        error_log("Erro ao gerar distribuição: " . $erro->getMessage());
        responderCarteira(500, [
            "sucesso" => false,
            "mensagem" => "Erro ao gerar distribuição da carteira.",
            "distribuicao" => []
        ]);
    }
}

function buscarPosicaoAtivoUsuario(): void
{
    global $conexao;

    try {
        $idUsuario = autenticar();
        $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));

        if ($symbol === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
            responderCarteira(400, [
                'success' => false,
                'sucesso' => false,
                'mensagem' => 'Ticker ausente ou inválido.'
            ]);
            return;
        }

        $stmt = $conexao->prepare("
            SELECT
                COALESCE(SUM(ii.quantidade_item), 0) AS quantidade,
                CASE
                    WHEN COALESCE(SUM(ii.quantidade_item), 0) > 0
                    THEN SUM(ii.quantidade_item * ii.preco_medio_item)
                         / SUM(ii.quantidade_item)
                    ELSE NULL
                END AS preco_medio
            FROM carteira c
            INNER JOIN item_investimento ii
                ON ii.id_carteira = c.id_carteira
            INNER JOIN ativo a
                ON a.id_ativo = ii.id_ativo
            WHERE c.id_usuario = :id_usuario
              AND UPPER(a.simbolo_ativo) = :symbol
              AND ii.quantidade_item > 0
        ");

        $stmt->execute([
            ':id_usuario' => $idUsuario,
            ':symbol' => $symbol
        ]);

        $linha = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $quantidade = max(0.0, (float) ($linha['quantidade'] ?? 0));
        $precoMedioRaw = $linha['preco_medio'] ?? null;
        $precoMedio = $quantidade > 0 && is_numeric($precoMedioRaw) ? (float) $precoMedioRaw : null;

        // Posição em cada carteira: a venda pergunta primeiro de qual carteira sai o ativo.
        $porCarteira = $conexao->prepare("
            SELECT c.id_carteira, c.nome_carteira, c.tipo_carteira::text AS tipo_carteira,
                   ii.quantidade_item AS quantidade, ii.preco_medio_item AS preco_medio
              FROM carteira c
              JOIN item_investimento ii ON ii.id_carteira = c.id_carteira
              JOIN ativo a ON a.id_ativo = ii.id_ativo
             WHERE c.id_usuario = :id_usuario
               AND UPPER(a.simbolo_ativo) = :symbol
               AND ii.quantidade_item > 0
             ORDER BY c.tipo_carteira, c.nome_carteira
        ");
        $porCarteira->execute([':id_usuario' => $idUsuario, ':symbol' => $symbol]);
        $carteiras = array_map(static fn(array $c): array => [
            'id_carteira' => (int) $c['id_carteira'],
            'nome_carteira' => $c['nome_carteira'],
            'tipo_carteira' => $c['tipo_carteira'],
            'quantidade' => (float) $c['quantidade'],
            'preco_medio' => (float) $c['preco_medio'],
        ], $porCarteira->fetchAll(PDO::FETCH_ASSOC));

        responderCarteira(200, [
            'success' => true,
            'sucesso' => true,
            'symbol' => $symbol,
            'quantidade' => $quantidade,
            'precoMedio' => $precoMedio,
            'hasPosition' => $quantidade > 0 && $precoMedio !== null,
            'carteiras' => $carteiras
        ]);
    } catch (Throwable $erro) {
        error_log('Failed to retrieve asset position: ' . $erro->getMessage());

        responderCarteira(500, [
            'success' => false,
            'sucesso' => false,
            'mensagem' => 'Não foi possível consultar a posição do ativo.'
        ]);
    }
}

// POST /carteiras/excluir-carteira?id_carteira=
// Simulada: pode ser excluída a qualquer momento (o dinheiro dela é fictício e próprio).
// Real: só sem ativos em aberto, para que nenhum valor investido desapareça do patrimônio.
function excluirCarteira(): void
{
    global $conexao;
    $idUsuario = (int) autenticar();

    $idCarteira = filter_var($_GET['id_carteira'] ?? null, FILTER_VALIDATE_INT);

    if (!$idCarteira) {
        responderCarteira(400, ['success' => false, 'message' => "ID da carteira não fornecido"]);
        return;
    }

    try {
        $carteira = buscarCarteiraDoUsuario($conexao, $idCarteira, $idUsuario);

        if (!$carteira) {
            responderCarteira(404, ['success' => false, 'message' => "Carteira não encontrada"]);
            return;
        }

        if ($carteira['tipo_carteira'] === 'Real' && contarPosicoesAbertas($conexao, $idCarteira) > 0) {
            responderCarteira(409, [
                'success' => false,
                'message' => "Venda os ativos desta carteira antes de excluí-la."
            ]);
            return;
        }

        deletarCarteiraService($conexao, $idUsuario, $idCarteira);

        responderCarteira(200, ['success' => true, 'message' => "Carteira excluída com sucesso"]);
    } catch (Throwable $error) {
        error_log("Erro ao excluir carteira: " . $error->getMessage());
        responderCarteira(500, ['success' => false, 'message' => "Não foi possível excluir a carteira agora."]);
    }
}

// GET /carteiras/saldo
function buscarSaldoController()
{
    global $conexao;

    try {
        $id_usuario = autenticar();
        $saldo = buscarSaldoRepository($conexao, $id_usuario);

        if ($saldo === null) {
            responderCarteira(404, ['success' => false, 'message' => 'Usuário não encontrado']);
            return;
        }

        responderCarteira(200, ['success' => true, 'saldo_atual' => $saldo]);
    } catch (Throwable $e) {
        error_log('Erro ao buscar o saldo: ' . $e->getMessage());
        responderCarteira(500, ['success' => false, 'message' => 'Erro ao consultar o saldo']);
    }
}

// POST|GET /carteiras/adicionar-saldo?valor=500.00   (depósito fictício no saldo geral)
function adicionarSaldoController()
{
    global $conexao;

    try {
        $idUsuario = (int) autenticar();
        $valor = valorMonetarioValido($_GET['valor'] ?? lerCorpoJson()['valor'] ?? null);

        if ($valor === null) {
            responderCarteira(400, ['success' => false, 'message' => 'Valor de depósito inválido.']);
            return;
        }

        $conexao->beginTransaction();
        $novoSaldo = adicionarSaldoService($conexao, $idUsuario, number_format($valor, 2, ".", ""));

        if ($novoSaldo === null) {
            $conexao->rollBack();
            responderCarteira(404, ['success' => false, 'message' => 'Usuário não encontrado']);
            return;
        }

        registrarDeposito($conexao, $idUsuario, null, $valor, "Depósito no saldo geral");
        $conexao->commit();

        notificarUsuario($conexao, $idUsuario, "Saldo adicionado", formatarReais($valor) . " foram adicionados ao seu saldo geral.", "Sistema");

        responderCarteira(200, [
            'success' => true,
            'message' => 'Saldo adicionado com sucesso',
            'saldo_atual' => $novoSaldo
        ]);
    } catch (Throwable $e) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        error_log('Erro ao adicionar o saldo: ' . $e->getMessage());
        responderCarteira(500, ['success' => false, 'message' => "Erro ao adicionar saldo"]);
    }
}

// POST /carteiras/depositar-simulacao   corpo: { "id_carteira": 7, "valor": 1000 }
// Coloca mais dinheiro fictício em uma carteira simulada (não mexe no saldo geral).
function depositarSimulacaoController()
{
    global $conexao;
    $idUsuario = (int) autenticar();

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        responderCarteira(405, ["success" => false, "message" => "Use POST."]);
        return;
    }

    $dados = lerCorpoJson();
    $idCarteira = filter_var($dados["id_carteira"] ?? null, FILTER_VALIDATE_INT);
    $valor = valorMonetarioValido(is_numeric($dados["valor"] ?? null) ? number_format((float) $dados["valor"], 2, ".", "") : "");

    if (!$idCarteira || $valor === null) {
        responderCarteira(400, ["success" => false, "message" => "Informe a carteira e um valor válido (até R$ 10.000.000,00)."]);
        return;
    }

    try {
        $carteira = buscarCarteiraDoUsuario($conexao, $idCarteira, $idUsuario);

        if (!$carteira) {
            responderCarteira(404, ["success" => false, "message" => "Carteira não encontrada."]);
            return;
        }

        if ($carteira["tipo_carteira"] !== "Simulada") {
            responderCarteira(400, ["success" => false, "message" => "Carteiras reais usam o saldo geral da conta."]);
            return;
        }

        $conexao->beginTransaction();
        $novoSaldo = depositarNaCarteiraSimulada($conexao, $idUsuario, $idCarteira, $valor, "Depósito na simulação");
        $conexao->commit();

        responderCarteira(200, [
            "success" => true,
            "message" => "Saldo da simulação atualizado",
            "saldo_simulacao" => $novoSaldo
        ]);
    } catch (Throwable $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        error_log("Erro ao depositar na simulação: " . $erro->getMessage());
        responderCarteira(500, ["success" => false, "message" => "Não foi possível depositar agora."]);
    }
}
