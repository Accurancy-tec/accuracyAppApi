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
function fiiDadosDaCotacao(array $item): array
{
    $dados = isset($item['data']) && is_array($item['data']) ? $item['data'] : $item;
    if (!isset($dados['symbol']) && isset($item['symbol'])) {
        $dados['symbol'] = $item['symbol'];
    }
    if (!isset($dados['logourl']) && isset($item['logourl'])) {
        $dados['logourl'] = $item['logourl'];
    }
    return $dados;
}


function fiiHistoricoFallback(array $symbols): array
{
    $symbols = array_values(array_unique(array_filter(array_map(
        static fn($s) => strtoupper(trim((string) $s)), $symbols
    ))));
    if (!$symbols) return [];

    $fuso = new DateTimeZone('America/Sao_Paulo');
    $hoje = new DateTimeImmutable('today', $fuso);
    $inicio = $hoje->modify('-20 days');
    $resposta = brapiRequest('v2/fii/historical', [
        'symbols' => implode(',', array_slice($symbols, 0, 20)),
        'startDate' => $inicio->format('Y-m-d'),
        'endDate' => $hoje->format('Y-m-d'),
        'sortOrder' => 'desc'
    ]);

    if (!$resposta['ok']) {
        error_log('Fallback de histórico dos FIIs indisponível. HTTP: ' . $resposta['status']);
        return [];
    }

    $body = json_decode((string) $resposta['body'], true);
    if (!is_array($body) || !isset($body['fiis']) || !is_array($body['fiis'])) return [];

    $resultado = [];
    foreach ($body['fiis'] as $serie) {
        if (!is_array($serie)) continue;
        $symbol = strtoupper(trim((string) ($serie['symbol'] ?? '')));
        $pontos = $serie['historicalDataPrice'] ?? [];
        if ($symbol === '' || !is_array($pontos)) continue;

        $pontosValidos = array_values(array_filter($pontos, static function ($p) {
            return is_array($p) && is_numeric($p['date'] ?? null)
                && is_numeric($p['close'] ?? null) && (float) $p['close'] > 0;
        }));
        usort($pontosValidos, static fn($a, $b) => (int) $b['date'] <=> (int) $a['date']);

        $resultado[$symbol] = [
            'latestClose' => isset($pontosValidos[0]) ? (float) $pontosValidos[0]['close'] : null,
            'previousClose' => isset($pontosValidos[1]) ? (float) $pontosValidos[1]['close'] : null,
            'latestDate' => isset($pontosValidos[0]['date']) ? (int) $pontosValidos[0]['date'] : null,
        ];
    }
    return $resultado;
}

function listarFiis(): void
{
    header('Content-Type: application/json; charset=UTF-8');

    $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? '20', FILTER_VALIDATE_INT);
    $search = trim((string) ($_GET['search'] ?? ''));
    $segmentType = trim((string) ($_GET['segmentType'] ?? ''));
    $sortBy = trim((string) ($_GET['sortBy'] ?? 'totalInvestors'));
    $sortOrder = trim((string) ($_GET['sortOrder'] ?? 'desc'));

    if ($page === false || $page === null || $limit === false || $limit === null
        || $page < 1 || $limit < 1 || $limit > 20) {
        responderErroBrapi('Página ou limite inválido (o limite permitido é de 1 a 20).', 400);
        return;
    }

    $segmentosPermitidos = ['papel', 'tijolo', 'hibrido', 'fof'];
    $ordenacoesPermitidas = [
        'symbol', 'name', 'segmentoAtuacao', 'mandate', 'price', 'navPerShare',
        'priceToNav', 'dividendYield12m', 'totalInvestors'
    ];
    if ($segmentType !== '' && !in_array($segmentType, $segmentosPermitidos, true)) {
        responderErroBrapi('Segmento inválido.', 400);
        return;
    }
    if (!in_array($sortBy, $ordenacoesPermitidas, true)) {
        responderErroBrapi('Ordenação inválida.', 400);
        return;
    }
    if (!in_array($sortOrder, ['asc', 'desc'], true)) {
        responderErroBrapi('Ordem inválida.', 400);
        return;
    }
    if (strlen($search) > 400) {
        responderErroBrapi('A pesquisa deve ter no máximo 400 bytes.', 400);
        return;
    }

    $query = ['page' => $page, 'limit' => $limit, 'sortBy' => $sortBy, 'sortOrder' => $sortOrder];
    if ($search !== '') $query['search'] = $search;
    if ($segmentType !== '') $query['segmentType'] = $segmentType;

    $respostaLista = brapiRequest('v2/fii/list', $query);
    if (!$respostaLista['ok']) {
        $status = (int) ($respostaLista['status'] ?? 502);
        if ($status === 403) {
            responderErroBrapi('O plano da brapi não liberou a listagem detalhada de FIIs.', 403);
            return;
        }
        error_log('Erro ao listar FIIs na brapi. HTTP: ' . $status);
        responderErroBrapi('Não foi possível consultar os FIIs na brapi.', 502);
        return;
    }

    $dadosLista = json_decode((string) $respostaLista['body'], true);
    if (!is_array($dadosLista) || !isset($dadosLista['fiis']) || !is_array($dadosLista['fiis'])) {
        error_log('Resposta de listagem de FIIs em formato inesperado.');
        responderErroBrapi('Resposta inválida da brapi ao listar FIIs.');
        return;
    }

    $fiisValidos = [];
    $symbols = [];
    foreach ($dadosLista['fiis'] as $fii) {
        if (!is_array($fii)) continue;
        $symbol = strtoupper(trim((string) ($fii['symbol'] ?? $fii['stock'] ?? '')));
        if ($symbol === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) continue;
        $fii['_normalizedSymbol'] = $symbol;
        $fiisValidos[] = $fii;
        $symbols[] = $symbol;
    }

    $quotesBySymbol = [];
    if ($symbols) {
        $respostaCotacoes = brapiRequest('quote/' . implode(',', $symbols));
        if ($respostaCotacoes['ok']) {
            $cotacoesJson = json_decode((string) $respostaCotacoes['body'], true);
            foreach (($cotacoesJson['results'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $cotacao = fiiDadosDaCotacao($item);

                $aliases = [
                    $item['requestedSymbol'] ?? null,
                    $item['symbol'] ?? null,
                    $cotacao['symbol'] ?? null,
                ];
                foreach ($aliases as $alias) {
                    $alias = strtoupper(trim((string) $alias));
                    if ($alias !== '') $quotesBySymbol[$alias] = $cotacao;
                }
            }
        } else {
            error_log('Cotação em lote de FIIs indisponível. HTTP: ' . ($respostaCotacoes['status'] ?? 502));
        }
    }

    $precisaHistorico = [];
    foreach ($fiisValidos as $fii) {
        $symbol = $fii['_normalizedSymbol'];
        $quote = $quotesBySymbol[$symbol] ?? [];
        $price = $quote['regularMarketPrice'] ?? $fii['price'] ?? null;
        $change = $quote['regularMarketChangePercent'] ?? $quote['changePercent'] ?? null;
        if (!is_numeric($price) || (float) $price <= 0 || !is_numeric($change)) $precisaHistorico[] = $symbol;
    }
    $historicoBySymbol = fiiHistoricoFallback($precisaHistorico);

    $stocks = [];
    foreach ($fiisValidos as $fii) {
        $symbol = $fii['_normalizedSymbol'];
        $quote = $quotesBySymbol[$symbol] ?? [];
        $historico = $historicoBySymbol[$symbol] ?? [];

        $price = $quote['regularMarketPrice'] ?? $fii['price'] ?? null;
        $previousClose = $quote['regularMarketPreviousClose'] ?? $quote['previousClose'] ?? null;
        $change = $quote['regularMarketChangePercent'] ?? $quote['changePercent'] ?? null;
        $marketTime = $quote['regularMarketTime'] ?? null;

        if ((!is_numeric($price) || (float) $price <= 0)
            && is_numeric($historico['latestClose'] ?? null) && (float) $historico['latestClose'] > 0) {
            $price = $historico['latestClose'];
        }
        if (!is_numeric($change) && is_numeric($price) && is_numeric($previousClose) && (float) $previousClose > 0) {
            $change = (((float) $price - (float) $previousClose) / (float) $previousClose) * 100.0;
        }
        if (!is_numeric($change) && is_numeric($historico['latestClose'] ?? null)
            && is_numeric($historico['previousClose'] ?? null) && (float) $historico['previousClose'] > 0) {
            $change = (((float) $historico['latestClose'] - (float) $historico['previousClose'])
                / (float) $historico['previousClose']) * 100.0;
        }
        if (!is_numeric($price) || (float) $price <= 0) $price = null;
        if (!is_numeric($change) || !is_finite((float) $change)) $change = null;

        $logo = $quote['logourl'] ?? $quote['logoUrl'] ?? $quote['logo']
            ?? $fii['logo'] ?? $fii['logoUrl'] ?? null;
        if (!is_string($logo) || trim($logo) === '') {
            $logo = 'https://icons.brapi.dev/icons/' . rawurlencode($symbol) . '.svg';
        }
        $nome = $quote['longName'] ?? $quote['shortName'] ?? $fii['name'] ?? $symbol;
        $volume = $quote['regularMarketVolume'] ?? $fii['volume'] ?? null;

        $stocks[] = [
            'stock' => $symbol,
            'name' => is_string($nome) && trim($nome) !== '' ? $nome : $symbol,
            'close' => $price !== null ? (float) $price : null,
            'change' => $change !== null ? (float) $change : null,
            'volume' => is_numeric($volume) ? (int) $volume : null,
            'logo' => $logo,
            'marketTime' => is_string($marketTime) ? $marketTime : null,
        ];
    }

    $pagination = $dadosLista['pagination'] ?? [];
    echo json_encode([
        'success' => true,
        'stocks' => $stocks,
        'paginacao' => [
            'paginaAtual' => $pagination['page'] ?? $page,
            'totalPaginas' => $pagination['totalPages'] ?? 0,
            'totalAtivos' => $pagination['totalItems'] ?? null,
            'temProximaPagina' => $pagination['hasNextPage'] ?? false,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function getFiiQuote(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $ticker = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    if ($ticker === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $ticker)) {
        responderErroBrapi('Ticker de FII ausente ou inválido.', 400);
        return;
    }

    // A cotação básica é /api/quote/{ticker}; não /api/v2/stocks/quote para FIIs.
    $resultado = brapiRequest('quote/' . rawurlencode($ticker));
    if (!$resultado['ok']) {
        $status = (int) ($resultado['status'] ?? 502);
        responderErroBrapi('Não foi possível consultar a cotação do FII na brapi.',
            $status >= 400 && $status < 600 ? $status : 502);
        return;
    }
    echo $resultado['body'];
}

function historicoFiis(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $range = trim((string) ($_GET['range'] ?? '3mo'));
    $intervalos = ['7d' => '-7 days', '1mo' => '-1 month', '3mo' => '-3 months',
        '6mo' => '-6 months', '1y' => '-1 year'];

    if ($symbol === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
        responderErroBrapi('Ticker de FII ausente ou inválido.', 400);
        return;
    }
    if (!isset($intervalos[$range])) {
        responderErroBrapi('Período de histórico inválido.', 400);
        return;
    }

    $fuso = new DateTimeZone('America/Sao_Paulo');
    $hoje = new DateTimeImmutable('today', $fuso);
    $inicio = $hoje->modify($intervalos[$range]);
    $resultado = brapiRequest('v2/fii/historical', [
        'symbols' => $symbol,
        'startDate' => $inicio->format('Y-m-d'),
        'endDate' => $hoje->format('Y-m-d'),
        'sortOrder' => 'asc',
    ]);

    if (!$resultado['ok']) {
        $status = (int) ($resultado['status'] ?? 502);
        if ($status === 403) {
            responderErroBrapi('O plano da brapi não liberou o histórico diário deste FII.', 403);
            return;
        }
        responderErroBrapi('Não foi possível consultar o histórico diário do FII na brapi.',
            $status >= 400 && $status < 600 ? $status : 502);
        return;
    }

    $dados = json_decode((string) $resultado['body'], true);
    if (!is_array($dados) || !isset($dados['fiis']) || !is_array($dados['fiis'])) {
        error_log('Resposta do histórico de FIIs em formato inesperado.');
        responderErroBrapi('Resposta de histórico de FIIs inválida.');
        return;
    }

    $results = [];
    foreach ($dados['fiis'] as $fii) {
        if (!is_array($fii)) continue;
        $requested = (string) ($fii['symbol'] ?? $symbol);
        $points = $fii['historicalDataPrice'] ?? [];
        if (!is_array($points)) $points = [];
        $results[] = [
            'requestedSymbol' => $symbol,
            'symbol' => $requested,
            'data' => [
                'usedRange' => $range,
                'usedInterval' => '1d',
                'historicalDataPrice' => $points,
            ],
        ];
    }
    echo json_encode(['results' => $results, 'requestedAt' => $dados['requestedAt'] ?? null,
        'took' => $dados['took'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}