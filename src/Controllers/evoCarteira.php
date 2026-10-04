<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/cors.php";
require_once __DIR__ . "/../database/conexao.php";
require_once __DIR__ . "/../Authentication/Authentication.php";
require_once __DIR__ . "/../service/aporteRecorrenteService.php";   // carteiraPertenceAoUsuario() e dataHojeCron()

// período => [quantos passos para trás, tamanho do passo em dias]
const PERIODOS_EVOLUCAO = [
    "1M" => [29, 1],    // 30 pontos, 1 por dia
    "6M" => [26, 7],    // 27 pontos, 1 por semana
    "1A" => [52, 7],    // 53 pontos, 1 por semana
];

function responderEvolucao(array $corpo, $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    responderEvolucao(["sucesso" => false, "mensagem" => "Método não permitido. Use GET."], 405);
}

$idUsuario = autenticar();

$periodo = strtoupper(trim((string) ($_GET["periodo"] ?? "6M")));

if (!isset(PERIODOS_EVOLUCAO[$periodo])) {
    responderEvolucao(["sucesso" => false, "mensagem" => "periodo inválido. Use 1M, 6M ou 1A."], 400);
}

$idCarteira = $_GET["id_carteira"] ?? null;
$temCarteira = $idCarteira !== null && $idCarteira !== "";

try {

    if ($temCarteira && !carteiraPertenceAoUsuario($conexao, $idCarteira, $idUsuario)) {
        responderEvolucao(["sucesso" => false, "mensagem" => "A carteira não pertence ao usuário."], 403);
    }

    $filtroCarteira = $temCarteira ? " AND a.id_carteira = :carteira" : "";
    $paramsBase = [":usuario" => $idUsuario];

    if ($temCarteira) {
        $paramsBase[":carteira"] = $idCarteira;
    }

    // ---------- 1) data do primeiro aporte (quando a carteira "nasceu") ----------
    $stmtPrimeira = $conexao->prepare(
        "SELECT MIN(a.data_aporte)
         FROM aporte a
         JOIN carteira c ON c.id_carteira = a.id_carteira
         WHERE c.id_usuario = :usuario AND a.status_aporte <> 'Cancelada' $filtroCarteira"
    );
    $stmtPrimeira->execute($paramsBase);
    $primeiraData = $stmtPrimeira->fetchColumn();

    if (!$primeiraData) {
        responderEvolucao([
            "sucesso" => true,
            "periodo" => $periodo,
            "primeira_data" => null,
            "valor_atual" => 0,
            "pontos" => []
        ]);
    }

    // ---------- 2) datas da série (de trás para frente, terminando HOJE) ----------
    [$passos, $tamanhoPasso] = PERIODOS_EVOLUCAO[$periodo];

    $hoje = new DateTimeImmutable(dataHojeCron());
    $datas = [];

    for ($k = $passos; $k >= 0; $k--) {
        $dia = $hoje->modify("-" . ($k * $tamanhoPasso) . " days")->format("Y-m-d");

        if ($dia >= $primeiraData) {
            $datas[] = $dia;
        }
    }

    // o primeiro aporte caiu no meio de um passo: garante um ponto no dia dele
    if ($primeiraData <= $hoje->format("Y-m-d") && (empty($datas) || $datas[0] > $primeiraData)) {
        $primeiraNoPeriodo = $hoje->modify("-" . ($passos * $tamanhoPasso) . " days")->format("Y-m-d");

        if ($primeiraData >= $primeiraNoPeriodo) {
            array_unshift($datas, $primeiraData);
        }
    }

    if (empty($datas)) {
        responderEvolucao(["sucesso" => true, "periodo" => $periodo, "primeira_data" => $primeiraData, "valor_atual" => 0, "pontos" => []]);
    }

    // ---------- 3) valor da carteira em cada data ----------
    $sql = "
        WITH ap AS (
            SELECT a.id_ativo, a.tipo_aporte, a.quantidade_aporte, a.valor_aporte, a.data_aporte, a.id_aporte
            FROM aporte a
            JOIN carteira c ON c.id_carteira = a.id_carteira
            WHERE c.id_usuario = :usuario
              AND a.status_aporte <> 'Cancelada'
              $filtroCarteira
        ),
        datas AS (
            SELECT d::date AS dia FROM unnest(CAST(:datas AS date[])) AS d
        ),
        posicao AS (
            SELECT dt.dia, ap.id_ativo,
                   SUM(CASE ap.tipo_aporte
                           WHEN 'Compra' THEN ap.quantidade_aporte
                           WHEN 'Venda'  THEN -ap.quantidade_aporte
                           ELSE 0
                       END) AS qtd
            FROM datas dt
            JOIN ap ON ap.data_aporte <= dt.dia
            GROUP BY dt.dia, ap.id_ativo
            HAVING SUM(CASE ap.tipo_aporte
                           WHEN 'Compra' THEN ap.quantidade_aporte
                           WHEN 'Venda'  THEN -ap.quantidade_aporte
                           ELSE 0
                       END) > 0.00000001
        )
        SELECT p.dia,
               SUM(p.qtd * COALESCE(cot.preco, apr.preco, 0)) AS valor
        FROM posicao p
        LEFT JOIN LATERAL (
            SELECT c2.preco_fechamento_cotacao AS preco
            FROM cotacao_ativo c2
            WHERE c2.id_ativo = p.id_ativo AND c2.data_cotacao <= p.dia
            ORDER BY c2.data_cotacao DESC
            LIMIT 1
        ) cot ON TRUE
        LEFT JOIN LATERAL (
            SELECT a2.valor_aporte / NULLIF(a2.quantidade_aporte, 0) AS preco
            FROM ap a2
            WHERE a2.id_ativo = p.id_ativo
              AND a2.data_aporte <= p.dia
              AND a2.tipo_aporte IN ('Compra', 'Venda')
              AND a2.quantidade_aporte > 0
            ORDER BY a2.data_aporte DESC, a2.id_aporte DESC
            LIMIT 1
        ) apr ON TRUE
        GROUP BY p.dia
        ORDER BY p.dia
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute($paramsBase + [":datas" => "{" . implode(",", $datas) . "}"]);

    $valorPorDia = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $valorPorDia[$linha["dia"]] = (float) $linha["valor"];
    }

    // dia sem nenhuma posição (vendeu tudo) = carteira valendo 0
    $pontos = [];

    foreach ($datas as $dia) {
        $pontos[] = ["data" => $dia, "valor" => round($valorPorDia[$dia] ?? 0.0, 2)];
    }

    responderEvolucao([
        "sucesso" => true,
        "periodo" => $periodo,
        "primeira_data" => $primeiraData,
        "valor_atual" => end($pontos)["valor"],
        "pontos" => $pontos
    ]);

} catch (Throwable $erro) {

    error_log("evolucaoCarteira.php: " . $erro->getMessage());

    responderEvolucao(["sucesso" => false, "mensagem" => "Erro ao montar a evolução da carteira."], 500);
}
?>