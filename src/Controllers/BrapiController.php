<?php

require_once __DIR__ . "/../../config/cors.php";

function brapiRequest(string $endpoint, array $query = []): array
{
    $baseUrl = getenv("BRAPI_BASE_URL");
    $token = getenv("BRAPI_TOKEN");

    if (!is_string($baseUrl) || trim($baseUrl) === "") {
        return ["ok" => false, "status" => 500, "body" => null, "error" => "BRAPI_BASE_URL não configurada"];
    }
    if (!is_string($token) || trim($token) === "") {
        return ["ok" => false, "status" => 500, "body" => null, "error" => "BRAPI_TOKEN não configurado"];
    }

    $url = rtrim($baseUrl, "/") . "/" . ltrim($endpoint, "/");
    if (!empty($query)) {
        $url .= "?" . http_build_query($query);
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $token,
            "Accept: application/json"
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $body = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($body === false) {
        error_log("Brapi cURL error: " . $curlError);
        return ["ok" => false, "status" => 502, "body" => null, "error" => "Falha de conexão com a Brapi"];
    }

    if ($status < 200 || $status >= 300) {
        error_log("Brapi HTTP status: " . $status . " endpoint=" . $endpoint);
        return ["ok" => false, "status" => $status, "body" => $body, "error" => "A Brapi retornou HTTP " . $status];
    }

    return ["ok" => true, "status" => $status, "body" => $body, "error" => null];
}

function responderErroBrapi(string $mensagem, int $status = 502): void
{
    http_response_code($status);
    echo json_encode([
        "success" => false,
        "sucesso" => false,
        "message" => $mensagem,
        "mensagem" => $mensagem
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function buscarAcao(string $ticker)
{
    $resultado = brapiRequest("v2/stocks/quote", ["symbols" => $ticker]);
    if (!$resultado["ok"]) {
        return false;
    }
    return $resultado["body"];
}

function buscarSymbols()
{
    $resultado = brapiRequest("v2/tickers");
    if (!$resultado["ok"]) {
        return false;
    }
    return $resultado["body"];
}

function getQuote(): void
{
    header("Content-Type: application/json; charset=UTF-8");
    $ticker = trim((string) ($_GET["symbol"] ?? ""));
    if ($ticker === "") {
        responderErroBrapi("Ticker não informado", 400);
        return;
    }
    if (!preg_match('/^[A-Za-z0-9.^_-]{1,24}$/', $ticker)) {
        responderErroBrapi("Ticker inválido", 400);
        return;
    }

    $resposta = buscarAcao($ticker);
    if ($resposta === false) {
        responderErroBrapi("Não foi possível consultar a cotação na Brapi");
        return;
    }
    echo $resposta;
}

function getSymbols(): void
{
    header("Content-Type: application/json; charset=UTF-8");
    $resposta = buscarSymbols();
    if ($resposta === false) {
        responderErroBrapi("Não foi possível listar os tickers na Brapi");
        return;
    }
    echo $resposta;
}

function listarAcoesNacionais(): void
{
    header("Content-Type: application/json; charset=UTF-8");

    $pageRaw = $_GET["page"] ?? "1";
    $limitRaw = $_GET["limit"] ?? "20";
    $page = filter_var($pageRaw, FILTER_VALIDATE_INT);
    $limit = filter_var($limitRaw, FILTER_VALIDATE_INT);

    if ($page === false || $page === null || $limit === false || $limit === null || $page < 1 || $limit < 1 || $limit > 20) {
        responderErroBrapi("Página inválida ou limite fora do intervalo (1 a 20)", 400);
        return;
    }

    $resultado = brapiRequest("quote/list", [
        "type" => "stock",
        "page" => $page,
        "limit" => $limit
    ]);

    if (!$resultado["ok"]) {
        responderErroBrapi("Erro ao consultar as ações na Brapi");
        return;
    }

    $dados = json_decode($resultado["body"], true);
    if (!is_array($dados) || !isset($dados["stocks"]) || !is_array($dados["stocks"])) {
        error_log("Brapi quote/list retornou JSON em formato inesperado");
        responderErroBrapi("Resposta inválida da Brapi");
        return;
    }

    $stocks = [];
    foreach ($dados["stocks"] as $stock) {
        if (!is_array($stock)) continue;
        $stocks[] = [
            "stock" => $stock["stock"] ?? $stock["symbol"] ?? "",
            "name" => $stock["name"] ?? $stock["longName"] ?? $stock["shortName"] ?? "",
            "close" => $stock["close"] ?? $stock["regularMarketPrice"] ?? null,
            "change" => $stock["change"] ?? $stock["regularMarketChangePercent"] ?? null,
            "volume" => $stock["volume"] ?? $stock["regularMarketVolume"] ?? null,
            "logo" => $stock["logo"] ?? $stock["logourl"] ?? $stock["logoUrl"] ?? null
        ];
    }

    echo json_encode([
        "success" => true,
        "stocks" => $stocks,
        "paginacao" => [
            "paginaAtual" => $dados["currentPage"] ?? $page,
            "totalPaginas" => $dados["totalPages"] ?? null,
            "totalAtivos" => $dados["totalCount"] ?? null,
            "temProximaPagina" => $dados["hasNextPage"] ?? false
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function historicoAcoes(): void
{
    header("Content-Type: application/json; charset=UTF-8");

    $symbol = strtoupper(trim((string) ($_GET["symbol"] ?? "")));
    $range = trim((string) ($_GET["range"] ?? "3mo"));
    $rangesPermitidos = ["7d", "1mo", "3mo", "6mo", "1y", "2y", "5y", "10y", "ytd", "max"];

    if ($symbol === "" || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
        responderErroBrapi("Ticker ausente ou inválido", 400);
        return;
    }
    if (!in_array($range, $rangesPermitidos, true)) {
        responderErroBrapi("Período inválido", 400);
        return;
    }

    $resultado = brapiRequest("v2/stocks/historical", [
        "symbols" => $symbol,
        "range" => $range,
        "interval" => "1d",
        "sortOrder" => "asc"
    ]);

    if (!$resultado["ok"]) {
        responderErroBrapi("Não foi possível consultar o histórico de preços na Brapi");
        return;
    }

    $dados = json_decode($resultado["body"], true);
    if (!is_array($dados) || !isset($dados["results"]) || !is_array($dados["results"])) {
        responderErroBrapi("Resposta de histórico em formato inválido");
        return;
    }

    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
