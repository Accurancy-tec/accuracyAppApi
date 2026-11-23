<?php

require_once __DIR__ . "/../../config/cors.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/BrapiService.php";
require_once __DIR__ . "/../../database/conexao.php";

/** Seconds a successful brapi response is reused (protects the free plan rate limit). */
const BRAPI_CACHE_TTL = 45;
/** Seconds an old cached response may still be served when brapi answers HTTP 429. */
const BRAPI_CACHE_STALE_MAX = 21600;

/** Cache file for one request. The key uses only endpoint and query: never the token. */
function brapiCacheFile(string $endpoint, array $query): string
{
    $dir = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . "accurancy_brapi_cache";
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    ksort($query);
    return $dir . DIRECTORY_SEPARATOR . sha1($endpoint . "?" . http_build_query($query)) . ".json";
}

/** Returns the cached body when it is younger than $maxAge seconds, otherwise null. */
function brapiCacheLer(string $arquivo, int $maxAge): ?string
{
    if (!is_file($arquivo)) {
        return null;
    }
    $idade = time() - (int) @filemtime($arquivo);
    if ($idade > $maxAge) {
        return null;
    }
    $conteudo = @file_get_contents($arquivo);
    return is_string($conteudo) && $conteudo !== "" ? $conteudo : null;
}

function brapiCacheGravar(string $arquivo, string $body): void
{
    // Best effort: a read-only temp dir must never break the request.
    @file_put_contents($arquivo, $body, LOCK_EX);
}

if (!function_exists("brapiHttpGet")) {
    /** Single HTTP GET against brapi. Returns body (or false), status, cURL error and Retry-After seconds. */
    function brapiHttpGet(string $url, string $token): array
    {
        $retryAfter = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $token,
                "Accept: application/json"
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $linha) use (&$retryAfter) {
                if (stripos($linha, "retry-after:") === 0) {
                    $retryAfter = (int) trim(substr($linha, 12));
                }
                return strlen($linha);
            }
        ]);

        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return ["body" => $body, "status" => $status, "curl_error" => $curlError, "retry_after" => $retryAfter];
    }
}

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

    $arquivoCache = brapiCacheFile($endpoint, $query);
    $recente = brapiCacheLer($arquivoCache, BRAPI_CACHE_TTL);
    if ($recente !== null) {
        return ["ok" => true, "status" => 200, "body" => $recente, "error" => null];
    }

    $resposta = brapiHttpGet($url, $token);

    // HTTP 429 (rate limit): wait a short time and try once more.
    if ($resposta["body"] !== false && $resposta["status"] === 429) {
        $espera = max(1, min(2, (int) $resposta["retry_after"]));
        error_log("Brapi HTTP status: 429 endpoint=" . $endpoint . " (nova tentativa em " . $espera . "s)");
        sleep($espera);
        $resposta = brapiHttpGet($url, $token);
    }

    $body = $resposta["body"];
    $status = $resposta["status"];

    if ($body === false) {
        error_log("Brapi cURL error: " . $resposta["curl_error"]);
        return ["ok" => false, "status" => 502, "body" => null, "error" => "Falha de conexão com a Brapi"];
    }

    if ($status < 200 || $status >= 300) {
        error_log("Brapi HTTP status: " . $status . " endpoint=" . $endpoint);
        if ($status === 429) {
            // Still limited: an older copy is better than an error screen.
            $antigo = brapiCacheLer($arquivoCache, BRAPI_CACHE_STALE_MAX);
            if ($antigo !== null) {
                return ["ok" => true, "status" => 200, "body" => $antigo, "error" => null];
            }
        }
        return ["ok" => false, "status" => $status, "body" => $body, "error" => "A Brapi retornou HTTP " . $status];
    }

    brapiCacheGravar($arquivoCache, (string) $body);
    return ["ok" => true, "status" => $status, "body" => $body, "error" => null];
}

function responderErroBrapi(string $mensagem, int $status = 502): void
{
    if ($status === 429) {
        $mensagem = "Muitas consultas ao provedor de cotações. Aguarde alguns segundos e tente novamente.";
    }
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

function historicoAcoes(): void
{
    header("Content-Type: application/json; charset=UTF-8");

    $symbol = strtoupper(trim((string) ($_GET["symbol"] ?? "")));
    $range = trim((string) ($_GET["range"] ?? "3mo"));
    $rangesPermitidos = ["1d", "7d", "1mo", "3mo", "6mo", "1y", "2y", "5y", "10y", "ytd", "max"];

    if ($symbol === "" || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
        responderErroBrapi("Ticker ausente ou inválido", 400);
        return;
    }
    if (!in_array($range, $rangesPermitidos, true)) {
        responderErroBrapi("Período inválido", 400);
        return;
    }

    $buscarHistorico = static function (string $rangeBusca, string $intervalo) use ($symbol): array {
        return brapiRequest("v2/stocks/historical", [
            "symbols" => $symbol,
            "range" => $rangeBusca,
            "interval" => $intervalo,
            "sortOrder" => "asc"
        ]);
    };
    $extrairDados = static function (array $resposta): ?array {
        if (!$resposta["ok"]) {
            return null;
        }
        $json = json_decode((string) $resposta["body"], true);
        return is_array($json) && isset($json["results"]) && is_array($json["results"]) ? $json : null;
    };
    $temPontos = static function (?array $json): bool {
        $pontos = $json["results"][0]["data"]["historicalDataPrice"] ?? [];
        return is_array($pontos) && count($pontos) > 0;
    };

    $resultado = $buscarHistorico($range, $range === "1d" ? "5m" : "1d");
    $dados = $extrairDados($resultado);

    // "Hoje" usa candles de 5 minutos. Nem todo ativo (ex.: alguns ETFs/fundos) tem
    // dado intradiario; nesse caso mostra as duas ultimas sessoes com candles diarios.
    if ($range === "1d" && !$temPontos($dados)) {
        error_log("Historico intradiario indisponivel para " . $symbol
            . " (HTTP " . (int) ($resultado["status"] ?? 0) . "); usando candles diarios.");
        $resultado = $buscarHistorico("7d", "1d");
        $dados = $extrairDados($resultado);
        if ($temPontos($dados)) {
            $pontos = $dados["results"][0]["data"]["historicalDataPrice"];
            usort($pontos, static function ($a, $b) {
                $dataA = is_array($a) && is_numeric($a["date"] ?? null) ? (int) $a["date"] : 0;
                $dataB = is_array($b) && is_numeric($b["date"] ?? null) ? (int) $b["date"] : 0;
                return $dataA <=> $dataB;
            });
            $dados["results"][0]["data"]["historicalDataPrice"] = array_slice($pontos, -2);
        }
    }

    if ($dados === null) {
        $statusBrapi = (int) ($resultado["status"] ?? 502);
        // 401 vira 502 para o app nao confundir com sessao expirada do usuario.
        $statusResposta = ($statusBrapi >= 400 && $statusBrapi < 600 && $statusBrapi !== 401)
            ? $statusBrapi
            : 502;
        responderErroBrapi("Não foi possível consultar o histórico de preços na Brapi", $statusResposta);
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
        static fn($s) => strtoupper(trim((string) $s)),
        $symbols
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

function listarAcoesNacionais(): void
{
    header("Content-Type: application/json; charset=UTF-8");

    $page = filter_var($_GET["page"] ?? "1", FILTER_VALIDATE_INT);
    $limit = filter_var($_GET["limit"] ?? "20", FILTER_VALIDATE_INT);

    $search = trim((string) ($_GET["search"] ?? ""));
    $sector = trim((string) ($_GET["sector"] ?? ""));
    $subsector = trim((string) ($_GET["subsector"] ?? ""));
    $sortBy = trim((string) ($_GET["sortBy"] ?? "volume"));
    $sortOrder = strtolower(trim((string) ($_GET["sortOrder"] ?? "desc")));

    if (
        $page === false || $page === null ||
        $limit === false || $limit === null ||
        $page < 1 || $limit < 1 || $limit > 20
    ) {
        responderErroBrapi("Página inválida ou limite fora do intervalo (1 a 20).", 400);
        return;
    }

    $ordenacoesPermitidas = [
        "name",
        "close",
        "change",
        "change_abs",
        "volume",
        "market_cap_basic"
    ];

    if (!in_array($sortBy, $ordenacoesPermitidas, true)) {
        responderErroBrapi("Critério de ordenação inválido.", 400);
        return;
    }

    if (!in_array($sortOrder, ["asc", "desc"], true)) {
        responderErroBrapi("Ordem de classificação inválida.", 400);
        return;
    }

    if (
        strlen($search) > 100 ||
        strlen($sector) > 120 ||
        strlen($subsector) > 120
    ) {
        responderErroBrapi("Um ou mais parâmetros de pesquisa excedem o tamanho permitido.", 400);
        return;
    }

    $query = [
        "type" => "stock",
        "page" => $page,
        "limit" => $limit,
        "sortBy" => $sortBy,
        "sortOrder" => $sortOrder
    ];

    if ($search !== "") {
        $query["search"] = $search;
    }

    if ($sector !== "") {
        $query["sector"] = $sector;
    }

    if ($subsector !== "") {
        $query["subsector"] = $subsector;
    }

    $resultado = brapiRequest("quote/list", $query);

    if (!$resultado["ok"]) {
        $status = (int) ($resultado["status"] ?? 502);

        if ($status < 400 || $status > 599) {
            $status = 502;
        }

        error_log("Erro ao listar ações na brapi. HTTP: " . $status);
        responderErroBrapi("Não foi possível consultar as ações na brapi.", $status);
        return;
    }

    $dados = json_decode((string) $resultado["body"], true);

    if (!is_array($dados) || !isset($dados["stocks"]) || !is_array($dados["stocks"])) {
        error_log("Brapi quote/list retornou JSON em formato inesperado.");
        responderErroBrapi("Resposta inválida da brapi ao listar ações.");
        return;
    }

    $stocks = [];

    foreach ($dados["stocks"] as $stock) {
        if (!is_array($stock)) {
            continue;
        }

        $symbol = strtoupper(trim((string) ($stock["stock"] ?? $stock["symbol"] ?? "")));

        if ($symbol === "" || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
            continue;
        }

        $name = $stock["name"] ?? $stock["longName"] ?? $stock["shortName"] ?? $symbol;
        $close = $stock["close"] ?? $stock["regularMarketPrice"] ?? null;
        $change = $stock["change"] ?? $stock["regularMarketChangePercent"] ?? null;
        $volume = $stock["volume"] ?? $stock["regularMarketVolume"] ?? null;
        $marketCap = $stock["market_cap"] ?? $stock["marketCap"] ?? null;
        $logo = $stock["logo"] ?? $stock["logourl"] ?? $stock["logoUrl"] ?? null;

        $stocks[] = [
            "stock" => $symbol,
            "name" => is_string($name) && trim($name) !== "" ? trim($name) : $symbol,
            "close" => is_numeric($close) ? (float) $close : null,
            "change" => is_numeric($change) ? (float) $change : null,
            "volume" => is_numeric($volume) ? (int) $volume : null,
            "marketCap" => is_numeric($marketCap) ? (float) $marketCap : null,
            "sector" => $stock["sector"] ?? null,
            "subsector" => $stock["subsector"] ?? null,
            "type" => $stock["type"] ?? null,
            "subType" => $stock["subType"] ?? null,
            "logo" => is_string($logo) && trim($logo) !== "" ? $logo : null
        ];
    }

    $availableSectors = $dados["availableSectors"] ?? [];
    $availableSubsectors = $dados["availableSubsectors"] ?? [];

    echo json_encode([
        "success" => true,
        "stocks" => $stocks,
        "filtros" => [
            "setores" => is_array($availableSectors) ? array_values($availableSectors) : [],
            "subsetores" => is_array($availableSubsectors) ? array_values($availableSubsectors) : []
        ],
        "paginacao" => [
            "paginaAtual" => $dados["currentPage"] ?? $page,
            "totalPaginas" => $dados["totalPages"] ?? 0,
            "totalAtivos" => $dados["totalCount"] ?? null,
            "temProximaPagina" => $dados["hasNextPage"] ?? false
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function listarAtivosInternacionais(): void
{
    header('Content-Type: application/json; charset=UTF-8');

    $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? '20', FILTER_VALIDATE_INT);
    $search = trim((string) ($_GET['search'] ?? ''));
    $sortBy = trim((string) ($_GET['sortBy'] ?? 'volume'));
    $sortOrder = strtolower(trim((string) ($_GET['sortOrder'] ?? 'desc')));

    if (
        $page === false || $page === null || $limit === false || $limit === null
        || $page < 1 || $limit < 1 || $limit > 20
    ) {
        responderErroBrapi('Página inválida ou limite fora do intervalo (1 a 20).', 400);
        return;
    }

    $ordenacoesPermitidas = ['name', 'close', 'change', 'change_abs', 'volume', 'market_cap_basic'];
    if (
        !in_array($sortBy, $ordenacoesPermitidas, true)
        || !in_array($sortOrder, ['asc', 'desc'], true)
    ) {
        responderErroBrapi('Critério de ordenação inválido.', 400);
        return;
    }
    if (strlen($search) > 100) {
        responderErroBrapi('Termo de pesquisa muito longo.', 400);
        return;
    }

    $fontes = [
        ['type' => 'bdr', 'subType' => null, 'label' => 'BDR'],
        ['type' => 'fund', 'subType' => 'etf', 'label' => 'ETF'],
    ];
    $ativos = [];

    foreach ($fontes as $fonte) {
        $query = [
            'type' => $fonte['type'],
            'page' => 1,
            'limit' => 2000,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
        ];
        if ($fonte['subType'] !== null) {
            $query['subType'] = $fonte['subType'];
        }
        if ($search !== '') {
            $query['search'] = $search;
        }

        $resposta = brapiRequest('quote/list', $query);
        if (!$resposta['ok']) {
            $status = (int) ($resposta['status'] ?? 502);
            responderErroBrapi(
                'Não foi possível consultar BDRs e ETFs na brapi.',
                $status >= 400 && $status < 600 ? $status : 502
            );
            return;
        }

        $dados = json_decode((string) $resposta['body'], true);
        if (!is_array($dados) || !isset($dados['stocks']) || !is_array($dados['stocks'])) {
            responderErroBrapi('Resposta inválida da brapi ao listar ativos internacionais.');
            return;
        }

        foreach ($dados['stocks'] as $item) {
            if (!is_array($item)) continue;
            $symbol = strtoupper(trim((string) ($item['stock'] ?? $item['symbol'] ?? '')));
            if ($symbol === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) continue;

            $name = $item['name'] ?? $item['longName'] ?? $item['shortName'] ?? $symbol;
            $close = $item['close'] ?? $item['regularMarketPrice'] ?? null;
            $change = $item['change'] ?? $item['regularMarketChangePercent'] ?? null;
            $volume = $item['volume'] ?? $item['regularMarketVolume'] ?? null;
            $marketCap = $item['market_cap'] ?? $item['marketCap'] ?? null;
            $logo = $item['logo'] ?? $item['logourl'] ?? $item['logoUrl'] ?? null;

            $ativos[] = [
                'stock' => $symbol,
                'name' => (is_string($name) && trim($name) !== '' ? trim($name) : $symbol)
                    . ' (' . $fonte['label'] . ')',
                'close' => is_numeric($close) ? (float) $close : null,
                'change' => is_numeric($change) ? (float) $change : null,
                'volume' => is_numeric($volume) ? (int) $volume : null,
                'marketCap' => is_numeric($marketCap) ? (float) $marketCap : null,
                'logo' => is_string($logo) && trim($logo) !== '' ? $logo : null,
            ];
        }
    }

    $campo = $sortBy === 'market_cap_basic' ? 'marketCap'
        : ($sortBy === 'change_abs' ? 'change' : $sortBy);
    usort($ativos, static function ($a, $b) use ($campo, $sortOrder) {
        $va = $a[$campo] ?? null;
        $vb = $b[$campo] ?? null;
        if ($campo === 'name' || $campo === 'stock') {
            $comparacao = strnatcasecmp((string) $va, (string) $vb);
        } else {
            $comparacao = (float) ($va ?? 0) <=> (float) ($vb ?? 0);
        }
        return $sortOrder === 'asc' ? $comparacao : -$comparacao;
    });

    $total = count($ativos);
    $totalPaginas = max(1, (int) ceil($total / $limit));
    $pagina = min($page, $totalPaginas);
    $paginaAtivos = array_slice($ativos, ($pagina - 1) * $limit, $limit);

    echo json_encode([
        'success' => true,
        'stocks' => $paginaAtivos,
        'paginacao' => [
            'paginaAtual' => $pagina,
            'totalPaginas' => $totalPaginas,
            'totalAtivos' => $total,
            'temProximaPagina' => $pagina < $totalPaginas,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
/**
 * Lista de FIIs sem o plano Pro: usa quote/list (subType=fii). Mantem o mesmo formato de
 * resposta de listarFiis(); os campos detalhados (segmento, P/VP, DY...) ficam nulos.
 */
function listarFiisPadrao(int $page, int $limit, string $search, string $sortBy, string $sortOrder): void
{
    $mapaOrdenacao = [
        'symbol' => 'name',
        'name' => 'name',
        'price' => 'close',
    ];
    $ordenarPor = $mapaOrdenacao[$sortBy] ?? 'volume';

    $query = [
        'type' => 'fund',
        'subType' => 'fii',
        'page' => $page,
        'limit' => $limit,
        'sortBy' => $ordenarPor,
        'sortOrder' => $sortOrder,
    ];
    if ($search !== '') {
        $query['search'] = $search;
    }

    $resposta = brapiRequest('quote/list', $query);
    if (!$resposta['ok']) {
        $status = (int) ($resposta['status'] ?? 502);
        error_log('Erro ao listar FIIs (lista padrao). HTTP: ' . $status);
        responderErroBrapi('Não foi possível consultar os FIIs na brapi.',
            $status === 429 ? 429 : 502);
        return;
    }

    $dados = json_decode((string) $resposta['body'], true);
    if (!is_array($dados) || !isset($dados['stocks']) || !is_array($dados['stocks'])) {
        error_log('Resposta da lista padrao de FIIs em formato inesperado.');
        responderErroBrapi('Resposta inválida da brapi ao listar FIIs.');
        return;
    }

    $stocks = [];
    foreach ($dados['stocks'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $symbol = strtoupper(trim((string) ($item['stock'] ?? $item['symbol'] ?? '')));
        if ($symbol === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
            continue;
        }

        $nome = $item['name'] ?? $item['longName'] ?? $item['shortName'] ?? $symbol;
        $close = $item['close'] ?? $item['regularMarketPrice'] ?? null;
        $change = $item['change'] ?? $item['regularMarketChangePercent'] ?? null;
        $volume = $item['volume'] ?? $item['regularMarketVolume'] ?? null;
        $logo = $item['logo'] ?? $item['logourl'] ?? $item['logoUrl'] ?? null;
        if (!is_string($logo) || trim($logo) === '') {
            $logo = 'https://icons.brapi.dev/icons/' . rawurlencode($symbol) . '.svg';
        }

        $stocks[] = [
            'stock' => $symbol,
            'name' => is_string($nome) && trim($nome) !== '' ? trim($nome) : $symbol,
            'close' => is_numeric($close) && (float) $close > 0 ? (float) $close : null,
            'change' => is_numeric($change) && is_finite((float) $change) ? (float) $change : null,
            'volume' => is_numeric($volume) ? (int) $volume : null,
            'logo' => $logo,
            'marketTime' => null,
            'segmentType' => null,
            'segmentoAtuacao' => null,
            'mandate' => null,
            'tipoGestao' => null,
            'navPerShare' => null,
            'priceToNav' => null,
            'dividendYield12m' => null,
            'totalInvestors' => null,
        ];
    }

    $paginacao = [];
    if (isset($dados['currentPage']) || isset($dados['totalPages'])) {
        $paginacao = $dados;
    }

    echo json_encode([
        'success' => true,
        'stocks' => $stocks,
        'paginacao' => [
            'paginaAtual' => (int) ($paginacao['currentPage'] ?? $page),
            'totalPaginas' => (int) ($paginacao['totalPages'] ?? 1),
            'totalAtivos' => isset($paginacao['totalCount']) ? (int) $paginacao['totalCount'] : null,
            'temProximaPagina' => (bool) ($paginacao['hasNextPage'] ?? false),
        ],
        'filtrosDetalhados' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function listarFiis(): void
{
    header('Content-Type: application/json; charset=UTF-8');

    $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? '20', FILTER_VALIDATE_INT);

    $search = trim((string) ($_GET['search'] ?? ''));
    $segmentType = trim((string) ($_GET['segmentType'] ?? ''));
    $segmentoAtuacao = trim((string) ($_GET['segmentoAtuacao'] ?? ''));
    $mandate = trim((string) ($_GET['mandate'] ?? ''));
    $tipoGestao = trim((string) ($_GET['tipoGestao'] ?? ''));
    $sortBy = trim((string) ($_GET['sortBy'] ?? 'totalInvestors'));
    $sortOrder = strtolower(trim((string) ($_GET['sortOrder'] ?? 'desc')));

    if (
        $page === false || $page === null ||
        $limit === false || $limit === null ||
        $page < 1 || $limit < 1 || $limit > 20
    ) {
        responderErroBrapi('Página ou limite inválido (o limite permitido é de 1 a 20).', 400);
        return;
    }

    $segmentosPermitidos = ['papel', 'tijolo', 'hibrido', 'fof'];

    $segmentosAtuacaoPermitidos = [
        'Logística',
        'Shoppings',
        'Escritórios',
        'Lajes Corporativas',
        'Títulos e Val. Mob.',
        'Residencial',
        'Hospital',
        'Hotel',
        'Educacional',
        'Híbrido',
        'Multicategoria',
        'Varejo',
        'Outros'
    ];

    $ordenacoesPermitidas = [
        'symbol',
        'name',
        'segmentoAtuacao',
        'mandate',
        'price',
        'navPerShare',
        'priceToNav',
        'dividendYield12m',
        'totalInvestors'
    ];

    if ($segmentType !== '' && !in_array($segmentType, $segmentosPermitidos, true)) {
        responderErroBrapi('Tipo de segmento inválido.', 400);
        return;
    }

    if (
        $segmentoAtuacao !== '' &&
        !in_array($segmentoAtuacao, $segmentosAtuacaoPermitidos, true)
    ) {
        responderErroBrapi('Segmento de atuação inválido.', 400);
        return;
    }

    if ($tipoGestao !== '' && !in_array($tipoGestao, ['Ativa', 'Definida'], true)) {
        responderErroBrapi('Tipo de gestão inválido.', 400);
        return;
    }

    if (!in_array($sortBy, $ordenacoesPermitidas, true)) {
        responderErroBrapi('Critério de ordenação inválido.', 400);
        return;
    }

    if (!in_array($sortOrder, ['asc', 'desc'], true)) {
        responderErroBrapi('Ordem de classificação inválida.', 400);
        return;
    }

    if (strlen($search) > 400 || strlen($mandate) > 120) {
        responderErroBrapi('Um ou mais parâmetros de pesquisa excedem o tamanho permitido.', 400);
        return;
    }

    $query = [
        'page' => $page,
        'limit' => $limit,
        'sortBy' => $sortBy,
        'sortOrder' => $sortOrder
    ];

    if ($search !== '') {
        $query['search'] = $search;
    }

    if ($segmentType !== '') {
        $query['segmentType'] = $segmentType;
    }

    if ($segmentoAtuacao !== '') {
        $query['segmentoAtuacao'] = $segmentoAtuacao;
    }

    if ($mandate !== '') {
        $query['mandate'] = $mandate;
    }

    if ($tipoGestao !== '') {
        $query['tipoGestao'] = $tipoGestao;
    }

    $respostaLista = brapiRequest('v2/fii/list', $query);

    if (!$respostaLista['ok']) {
        $status = (int) ($respostaLista['status'] ?? 502);

        // A lista detalhada (v2/fii/list) e exclusiva do plano Pro. Sem ela, usa a lista
        // padrao da brapi (quote/list, tipo FII), que funciona em todos os planos.
        if ($status === 401 || $status === 403) {
            error_log('v2/fii/list recusada (HTTP ' . $status . '); usando a lista padrao de FIIs.');
            listarFiisPadrao($page, $limit, $search, $sortBy, $sortOrder);
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
        if (!is_array($fii)) {
            continue;
        }

        $symbol = strtoupper(trim((string) ($fii['symbol'] ?? $fii['stock'] ?? '')));

        if ($symbol === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
            continue;
        }

        $fii['_normalizedSymbol'] = $symbol;
        $fiisValidos[] = $fii;
        $symbols[] = $symbol;
    }

    $quotesBySymbol = [];

    if ($symbols) {
        $respostaCotacoes = brapiRequest('quote/' . implode(',', $symbols));

        if ($respostaCotacoes['ok']) {
            $cotacoesJson = json_decode((string) $respostaCotacoes['body'], true);
            $resultadosCotacoes = $cotacoesJson['results'] ?? [];

            if (is_array($resultadosCotacoes)) {
                foreach ($resultadosCotacoes as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $cotacao = fiiDadosDaCotacao($item);
                    $aliases = [
                        $item['requestedSymbol'] ?? null,
                        $item['symbol'] ?? null,
                        $cotacao['symbol'] ?? null,
                    ];

                    foreach ($aliases as $alias) {
                        $alias = strtoupper(trim((string) $alias));

                        if ($alias !== '') {
                            $quotesBySymbol[$alias] = $cotacao;
                        }
                    }
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

        if (!is_numeric($price) || (float) $price <= 0 || !is_numeric($change)) {
            $precisaHistorico[] = $symbol;
        }
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

        if (
            (!is_numeric($price) || (float) $price <= 0) &&
            is_numeric($historico['latestClose'] ?? null) &&
            (float) $historico['latestClose'] > 0
        ) {
            $price = $historico['latestClose'];
        }

        if (
            !is_numeric($change) &&
            is_numeric($price) &&
            is_numeric($previousClose) &&
            (float) $previousClose > 0
        ) {
            $change = (((float) $price - (float) $previousClose) / (float) $previousClose) * 100.0;
        }

        if (
            !is_numeric($change) &&
            is_numeric($historico['latestClose'] ?? null) &&
            is_numeric($historico['previousClose'] ?? null) &&
            (float) $historico['previousClose'] > 0
        ) {
            $change = (
                ((float) $historico['latestClose'] - (float) $historico['previousClose']) /
                (float) $historico['previousClose']
            ) * 100.0;
        }

        if (!is_numeric($price) || (float) $price <= 0) {
            $price = null;
        }

        if (!is_numeric($change) || !is_finite((float) $change)) {
            $change = null;
        }

        $logo = $quote['logourl'] ?? $quote['logoUrl'] ?? $quote['logo']
            ?? $fii['logo'] ?? $fii['logoUrl'] ?? null;

        if (!is_string($logo) || trim($logo) === '') {
            $logo = 'https://icons.brapi.dev/icons/' . rawurlencode($symbol) . '.svg';
        }

        $nome = $quote['longName'] ?? $quote['shortName'] ?? $fii['name'] ?? $symbol;
        $volume = $quote['regularMarketVolume'] ?? $fii['volume'] ?? null;

        $stocks[] = [
            'stock' => $symbol,
            'name' => is_string($nome) && trim($nome) !== '' ? trim($nome) : $symbol,
            'close' => $price !== null ? (float) $price : null,
            'change' => $change !== null ? (float) $change : null,
            'volume' => is_numeric($volume) ? (int) $volume : null,
            'logo' => $logo,
            'marketTime' => is_string($marketTime) ? $marketTime : null,
            'segmentType' => $fii['segmentType'] ?? null,
            'segmentoAtuacao' => $fii['segmentoAtuacao'] ?? null,
            'mandate' => $fii['mandate'] ?? null,
            'tipoGestao' => $fii['tipoGestao'] ?? null,
            'navPerShare' => is_numeric($fii['navPerShare'] ?? null)
                ? (float) $fii['navPerShare']
                : null,
            'priceToNav' => is_numeric($fii['priceToNav'] ?? null)
                ? (float) $fii['priceToNav']
                : null,
            'dividendYield12m' => is_numeric($fii['dividendYield12m'] ?? null)
                ? (float) $fii['dividendYield12m']
                : null,
            'totalInvestors' => is_numeric($fii['totalInvestors'] ?? null)
                ? (int) $fii['totalInvestors']
                : null,
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

    $resultado = brapiRequest('quote/' . rawurlencode($ticker));
    if (!$resultado['ok']) {
        $status = (int) ($resultado['status'] ?? 502);
        responderErroBrapi(
            'Não foi possível consultar a cotação do FII na brapi.',
            $status >= 400 && $status < 600 ? $status : 502
        );
        return;
    }
    echo $resultado['body'];
}

/**
 * Historico diario de um FII pelo endpoint padrao (v2/stocks/historical), sem plano Pro.
 * Retorna o mesmo formato de historicoFiis() ou null se a brapi recusar.
 */
function historicoFiisPadrao(string $symbol, string $range): ?array
{
    $rangeBusca = $range === '1d' ? '7d' : $range;
    $resposta = brapiRequest('v2/stocks/historical', [
        'symbols' => $symbol,
        'range' => $rangeBusca,
        'interval' => '1d',
        'sortOrder' => 'asc',
    ]);
    if (!$resposta['ok']) {
        error_log('Historico padrao de FII indisponivel. HTTP: ' . ($resposta['status'] ?? 0));
        return null;
    }

    $dados = json_decode((string) $resposta['body'], true);
    if (!is_array($dados) || !isset($dados['results']) || !is_array($dados['results'])) {
        return null;
    }

    $results = [];
    foreach ($dados['results'] as $item) {
        if (!is_array($item)) continue;
        $pontos = $item['data']['historicalDataPrice'] ?? [];
        if (!is_array($pontos)) $pontos = [];

        if ($range === '1d' && !empty($pontos)) {
            usort($pontos, static function ($a, $b) {
                $dataA = is_array($a) && is_numeric($a['date'] ?? null) ? (int) $a['date'] : 0;
                $dataB = is_array($b) && is_numeric($b['date'] ?? null) ? (int) $b['date'] : 0;
                return $dataA <=> $dataB;
            });
            $pontos = array_slice($pontos, -2);
        }

        $results[] = [
            'requestedSymbol' => $symbol,
            'symbol' => (string) ($item['symbol'] ?? $symbol),
            'data' => [
                'usedRange' => $range,
                'usedInterval' => '1d',
                'historicalDataPrice' => $pontos,
            ],
        ];
    }
    if (!$results) return null;

    return ['results' => $results, 'requestedAt' => $dados['requestedAt'] ?? null,
        'took' => $dados['took'] ?? null];
}

function historicoFiis(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $range = trim((string) ($_GET['range'] ?? '3mo'));
    $intervalos = [
        '1d' => '-7 days',
        '7d' => '-7 days',
        '1mo' => '-1 month',
        '3mo' => '-3 months',
        '6mo' => '-6 months',
        '1y' => '-1 year'
    ];

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
        // Historico detalhado (v2/fii/historical) e do plano Pro: usa o historico padrao.
        if ($status === 401 || $status === 403) {
            $padrao = historicoFiisPadrao($symbol, $range);
            if ($padrao !== null) {
                echo json_encode($padrao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
            responderErroBrapi('Não foi possível consultar o histórico deste FII na brapi.', 502);
            return;
        }
        responderErroBrapi(
            'Não foi possível consultar o histórico diário do FII na brapi.',
            $status >= 400 && $status < 600 ? $status : 502
        );
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

        // O historico de FIIs e diario. Para Hoje, pega as duas ultimas sessoes
        // disponiveis na ultima semana, inclusive apos fins de semana e feriados.
        if ($range === '1d' && !empty($points)) {
            usort($points, static function ($a, $b) {
                $dataA = is_array($a) && is_numeric($a['date'] ?? null) ? (int) $a['date'] : 0;
                $dataB = is_array($b) && is_numeric($b['date'] ?? null) ? (int) $b['date'] : 0;
                return $dataA <=> $dataB;
            });
            $points = array_slice($points, -2);
        }

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
    echo json_encode([
        'results' => $results,
        'requestedAt' => $dados['requestedAt'] ?? null,
        'took' => $dados['took'] ?? null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Curva de vencimentos de um ativo (ex.: WIN, DI1) com a cotacao do ultimo pregao.
 * Cache de 10 min em arquivo temporario. Retorna null se a brapi falhar.
 */
function futurosCurvaDoAtivo(string $ativo): ?array
{
    $arquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'accuracy_futuros_' . $ativo . '.json';
    if (is_file($arquivo) && (time() - (int) filemtime($arquivo)) < 600) {
        $emCache = json_decode((string) file_get_contents($arquivo), true);
        if (is_array($emCache)) {
            return $emCache;
        }
    }

    $curva = brapiRequest('v2/futures/term-structure', ['asset' => $ativo]);
    if (!$curva['ok']) {
        error_log('Futuros: term-structure falhou para ' . $ativo . ' (HTTP ' . (int) ($curva['status'] ?? 0) . ')');
        return null;
    }

    $dados = json_decode((string) $curva['body'], true);
    $contratos = is_array($dados) && is_array($dados['contracts'] ?? null) ? $dados['contracts'] : [];
    if (count($contratos) > 0) {
        @file_put_contents($arquivo, json_encode($contratos), LOCK_EX);
    }
    return $contratos;
}

/**
 * Lista padrao de futuros: so contratos COM cotacao dos ativos principais, no mesmo formato
 * da resposta de v2/futures/list (chaves "futures" e "pagination").
 */
function futurosPrincipaisComoLista(int $page, int $limit, string $sortBy, string $sortOrder, string $segment): ?array
{
    $ativos = [
        'WIN' => 'financial',
        'WDO' => 'financial',
        'DI1' => 'financial',
        'DAP' => 'financial',
        'BGI' => 'agribusiness',
        'CCM' => 'agribusiness',
        'ICF' => 'agribusiness',
        'SJC' => 'agribusiness',
    ];

    $positivo = static function ($valor): bool {
        return is_numeric($valor) && (float) $valor > 0;
    };

    $contratos = [];
    $algumaRespostaOk = false;
    foreach ($ativos as $ativo => $segmentoDoAtivo) {
        if ($segment !== '' && $segment !== $segmentoDoAtivo) {
            continue;
        }
        $curva = futurosCurvaDoAtivo($ativo);
        if ($curva === null) {
            continue;
        }
        $algumaRespostaOk = true;
        foreach ($curva as $contrato) {
            if (!is_array($contrato)) {
                continue;
            }
            $taxa = ($contrato['quotationType'] ?? '') === 'rate';
            $temCotacao = $taxa
                ? ($positivo($contrato['settlementRate'] ?? null) || $positivo($contrato['close'] ?? null))
                : ($positivo($contrato['settlement'] ?? null) || $positivo($contrato['close'] ?? null));
            if ($temCotacao) {
                $contratos[] = $contrato;
            }
        }
    }

    if (!$algumaRespostaOk) {
        return null;
    }

    $chave = static function (array $c) use ($sortBy): string {
        if ($sortBy === 'symbol') {
            return (string) ($c['symbol'] ?? '');
        }
        if ($sortBy === 'underlyingAsset') {
            return (string) ($c['underlyingAsset'] ?? '') . '|' . (string) ($c['expirationDate'] ?? '');
        }
        return (string) ($c['expirationDate'] ?? '') . '|' . (string) ($c['symbol'] ?? '');
    };
    $sentido = $sortOrder === 'desc' ? -1 : 1;
    usort($contratos, static function (array $a, array $b) use ($chave, $sentido): int {
        return $sentido * strnatcasecmp($chave($a), $chave($b));
    });

    $total = count($contratos);
    $totalPages = max(1, (int) ceil($total / max(1, $limit)));
    $page = min(max(1, $page), $totalPages);

    return [
        'futures' => array_slice($contratos, ($page - 1) * $limit, $limit),
        'pagination' => ['page' => $page, 'totalPages' => $totalPages, 'total' => $total],
    ];
}

function listarContratosFuturos(): void
{
    header('Content-Type: application/json; charset=UTF-8');

    $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? '20', FILTER_VALIDATE_INT);
    $search = strtoupper(trim((string) ($_GET['search'] ?? '')));
    $sortBy = trim((string) ($_GET['sortBy'] ?? 'expirationDate'));
    $sortOrder = strtolower(trim((string) ($_GET['sortOrder'] ?? 'asc')));
    $segment = strtolower(trim((string) ($_GET['segment'] ?? '')));

    if ($page === false || $page === null || $page < 1 || $limit === false || $limit === null || $limit < 1 || $limit > 20) {
        responderErroBrapi('Pagina invalida ou limite fora do intervalo (1 a 20).', 400);
        return;
    }
    if (
        !in_array($sortBy, ['symbol', 'expirationDate', 'underlyingAsset'], true)
        || !in_array($sortOrder, ['asc', 'desc'], true)
    ) {
        responderErroBrapi('Criterio de ordenacao de contratos invalido.', 400);
        return;
    }

    if ($segment !== '' && !in_array($segment, ['financial', 'agribusiness'], true)) {
        responderErroBrapi('Segmento de contratos invalido.', 400);
        return;
    }

    $query = ['page' => $page, 'limit' => $limit, 'sortBy' => $sortBy, 'sortOrder' => $sortOrder];
    if ($segment !== '') {
        $query['segment'] = $segment;
    }
    if ($search !== '') {
        if (preg_match('/^(.+?)[FGHJKMNQUVXZ]\d{2}$/', $search, $match)) {
            $search = $match[1];
        }
        if (!preg_match('/^[A-Z0-9]{2,6}$/', $search)) {
            responderErroBrapi('Pesquise pelo codigo do ativo ou contrato (ex.: WIN, WDO, WINZ26).', 400);
            return;
        }
        $query['asset'] = $search;
    }

    if ($search === '') {
        // Sem busca: so contratos com cotacao dos ativos principais (term-structure).
        $principais = futurosPrincipaisComoLista($page, $limit, $sortBy, $sortOrder, $segment);
        $lista = $principais === null
            ? ['ok' => false, 'status' => 502, 'body' => null]
            : ['ok' => true, 'status' => 200, 'body' => json_encode($principais)];
    } else {
        $lista = brapiRequest('v2/futures/list', $query);
    }
    if (!$lista['ok']) {
        $status = (int) ($lista['status'] ?? 502);
        $mensagem = $status === 403
            ? 'A listagem completa de futuros exige o plano Pro da brapi.'
            : 'Nao foi possivel consultar os contratos futuros na brapi.';
        responderErroBrapi($mensagem, $status >= 400 && $status < 600 ? $status : 502);
        return;
    }

    $dadosLista = json_decode((string) $lista['body'], true);
    if (!is_array($dadosLista) || !isset($dadosLista['futures']) || !is_array($dadosLista['futures'])) {
        responderErroBrapi('Resposta invalida da brapi ao listar contratos futuros.');
        return;
    }

    $contratos = [];
    $symbols = [];
    foreach ($dadosLista['futures'] as $future) {
        if (!is_array($future)) {
            continue;
        }
        $symbol = strtoupper(trim((string) ($future['symbol'] ?? '')));
        if ($symbol === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
            continue;
        }
        $future['_symbol'] = $symbol;
        $contratos[] = $future;
        $symbols[] = $symbol;
    }

    // First attempt: one batch quote request for the page (brapi accepts up to 20 symbols).
    $quotesBySymbol = [];
    if ($symbols) {
        $cotacoes = brapiRequest('v2/futures/quote', ['symbols' => implode(',', array_slice($symbols, 0, 20))]);
        if (!$cotacoes['ok']) {
            $status = (int) ($cotacoes['status'] ?? 502);
            $mensagem = $status === 403
                ? 'A cotacao de contratos futuros exige o plano Pro da brapi.'
                : 'Nao foi possivel consultar as cotacoes dos contratos futuros.';
            responderErroBrapi($mensagem, $status >= 400 && $status < 600 ? $status : 502);
            return;
        }

        $dadosCotacoes = json_decode((string) $cotacoes['body'], true);
        $quotes = is_array($dadosCotacoes) ? ($dadosCotacoes['quotes'] ?? []) : [];
        if (is_array($quotes)) {
            foreach ($quotes as $quote) {
                if (!is_array($quote)) {
                    continue;
                }
                $symbol = strtoupper(trim((string) ($quote['symbol'] ?? '')));
                if ($symbol !== '') {
                    $quotesBySymbol[$symbol] = $quote;
                }
            }
        }
    }

    // Fallback: when the batch quote omits a contract or has no usable value,
    // query the term structure once per underlying asset (not once per contract).
    $assetsNeedingFallback = [];
    foreach ($contratos as $future) {
        $symbol = $future['_symbol'];
        $quote = $quotesBySymbol[$symbol] ?? [];
        $quotationType = strtolower((string) ($future['quotationType'] ?? $quote['quotationType'] ?? 'price'));
        if ($quotationType === 'rate') {
            $hasValue = is_numeric($quote['settlementRate'] ?? null) || is_numeric($quote['close'] ?? null);
        } else {
            $hasValue = is_numeric($quote['settlement'] ?? null) || is_numeric($quote['close'] ?? null);
        }

        if (!$hasValue) {
            $asset = strtoupper(trim((string) ($future['underlyingAsset'] ?? '')));
            if ($asset !== '' && preg_match('/^[A-Z0-9]{2,8}$/', $asset)) {
                $assetsNeedingFallback[$asset] = true;
            }
        }
    }

    foreach (array_keys($assetsNeedingFallback) as $asset) {
        $curva = brapiRequest('v2/futures/term-structure', ['asset' => $asset]);
        if (!$curva['ok']) {
            error_log('Brapi futuros: fallback term-structure indisponivel para ' . $asset
                . ' (HTTP ' . (int) ($curva['status'] ?? 0) . ').');
            continue;
        }

        $dadosCurva = json_decode((string) $curva['body'], true);
        $contratosCurva = is_array($dadosCurva) ? ($dadosCurva['contracts'] ?? []) : [];
        if (!is_array($contratosCurva)) {
            continue;
        }

        foreach ($contratosCurva as $contratoCurva) {
            if (!is_array($contratoCurva)) {
                continue;
            }
            $symbolCurva = strtoupper(trim((string) ($contratoCurva['symbol'] ?? '')));
            if (
                $symbolCurva !== '' && isset($quotesBySymbol[$symbolCurva])
                && (is_numeric($quotesBySymbol[$symbolCurva]['settlement'] ?? null)
                    || is_numeric($quotesBySymbol[$symbolCurva]['settlementRate'] ?? null)
                    || is_numeric($quotesBySymbol[$symbolCurva]['close'] ?? null))
            ) {
                continue;
            }
            if ($symbolCurva !== '') {
                $quotesBySymbol[$symbolCurva] = $contratoCurva;
            }
        }
    }

    $stocks = [];
    foreach ($contratos as $future) {
        $symbol = $future['_symbol'];
        $quote = $quotesBySymbol[$symbol] ?? [];

        $descricao = trim((string) ($future['assetDescription'] ?? 'Contrato futuro'));
        if ($descricao === '') {
            $descricao = 'Contrato futuro';
        }

        $vencimento = trim((string) ($future['expirationDate'] ?? ''));
        if ($vencimento !== '') {
            $dataVencimento = DateTimeImmutable::createFromFormat('!Y-m-d', $vencimento);
            $descricao .= ' | Vencimento ' . ($dataVencimento instanceof DateTimeImmutable
                ? $dataVencimento->format('d/m/Y')
                : $vencimento);
        }

        $tipoCotacao = strtolower((string) ($future['quotationType'] ?? $quote['quotationType'] ?? 'price'));
        $ehTaxa = $tipoCotacao === 'rate';
        $priceLabel = null;
        $preco = null;

        if ($ehTaxa) {
            // In DI/DAP, display the annual adjustment rate, not the BRL settlement amount.
            $taxa = $quote['settlementRate'] ?? $quote['close'] ?? null;
            if (is_numeric($taxa) && (float) $taxa > 0) {
                $priceLabel = number_format((float) $taxa, 3, ',', '.') . '% a.a.';
            } else {
                $priceLabel = 'Sem cotação';
            }
        } else {
            // Prefer official settlement; use the last trade only if settlement is absent.
            $ajuste = $quote['settlement'] ?? null;
            $ultimoNegocio = $quote['close'] ?? null;
            // Rolagem (spread) pode ter valor zero ou negativo; nos demais, so valor > 0.
            $ehRolagem = stripos((string) ($future['assetDescription'] ?? ''), 'rolagem') !== false;
            $aceitaValor = static function ($valor) use ($ehRolagem): bool {
                return is_numeric($valor) && ($ehRolagem || (float) $valor > 0);
            };
            if ($aceitaValor($ajuste)) {
                $preco = (float) $ajuste;
            } elseif ($aceitaValor($ultimoNegocio)) {
                $preco = (float) $ultimoNegocio;
            }
            if ($preco !== null && $preco <= 0) {
                // O app mostra "—" para preco <= 0; envia o texto pronto.
                $priceLabel = 'R$ ' . number_format($preco, 2, ',', '.');
            }
            if ($preco === null) {
                $priceLabel = 'Sem cotação';
            }
        }

        $stocks[] = [
            'stock' => $symbol,
            'name' => $descricao,
            'close' => $preco,
            'change' => is_numeric($quote['oscillationPct'] ?? null) ? (float) $quote['oscillationPct'] : null,
            'volume' => is_numeric($quote['volume'] ?? null) ? (int) $quote['volume'] : null,
            'priceLabel' => $priceLabel,
            'logo' => null,
        ];
    }

    $pagination = $dadosLista['pagination'] ?? [];
    $pageCurrent = (int) ($pagination['page'] ?? $page);
    $totalPages = (int) ($pagination['totalPages'] ?? 1);
    $total = isset($pagination['total']) ? (int) $pagination['total'] : count($stocks);

    echo json_encode([
        'success' => true,
        'stocks' => $stocks,
        'paginacao' => [
            'paginaAtual' => max(1, $pageCurrent),
            'totalPaginas' => max(1, $totalPages),
            'totalAtivos' => $total,
            'temProximaPagina' => $pageCurrent < $totalPages,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function getFutureQuote(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    if ($symbol === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
        responderErroBrapi('Código do contrato futuro ausente ou inválido.', 400);
        return;
    }

    $resposta = brapiRequest('v2/futures/quote', ['symbols' => $symbol]);
    if (!$resposta['ok']) {
        $status = (int) ($resposta['status'] ?? 502);
        $mensagem = $status === 403
            ? 'A cotação deste contrato futuro exige o plano Pro da brapi.'
            : 'Não foi possível consultar a cotação do contrato futuro.';
        responderErroBrapi($mensagem, $status >= 400 && $status < 600 ? $status : 502);
        return;
    }

    $body = json_decode((string) $resposta['body'], true);
    $quotes = is_array($body) ? ($body['quotes'] ?? []) : [];
    if (!is_array($quotes)) $quotes = [];

    $results = [];
    foreach ($quotes as $quote) {
        if (!is_array($quote)) continue;
        $requested = strtoupper(trim((string) ($quote['symbol'] ?? $symbol)));
        $isRate = strtolower((string) ($quote['quotationType'] ?? '')) === 'rate';

        // Skip placeholder contracts: brapi may return every field as null with date = 0.
        $hasQuoteValue = is_numeric($quote['settlement'] ?? null)
            || is_numeric($quote['settlementRate'] ?? null)
            || is_numeric($quote['close'] ?? null);
        if (!$hasQuoteValue) continue;

        // Same rule as the list endpoint: settlement first, last trade only for price contracts.
        $price = null;
        if (is_numeric($quote['settlement'] ?? null)) {
            $price = (float) $quote['settlement'];
        } elseif (!$isRate && is_numeric($quote['close'] ?? null)) {
            $price = (float) $quote['close'];
        }

        $time = null;
        if (is_numeric($quote['date'] ?? null) && (int) $quote['date'] > 0) {
            $time = (new DateTimeImmutable('@' . (int) $quote['date']))
                ->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        }
        $data = [
            'symbol' => $requested,
            'shortName' => $requested,
            'longName' => (string) ($quote['assetDescription'] ?? $requested),
            'regularMarketVolume' => is_numeric($quote['volume'] ?? null) ? (int) $quote['volume'] : null,
            'regularMarketPrice' => $price,
            'regularMarketChangePercent' => is_numeric($quote['oscillationPct'] ?? null) ? (float) $quote['oscillationPct'] : null,
            'regularMarketTime' => $time,
            'regularMarketOpen' => null,
            'regularMarketDayHigh' => (!$isRate && is_numeric($quote['high'] ?? null)) ? (float) $quote['high'] : null,
            'fiftyTwoWeekHigh' => null,
            'fiftyTwoWeekLow' => null,
            'currency' => (string) ($quote['tradingCurrency'] ?? 'BRL'),
            'settlementRate' => is_numeric($quote['settlementRate'] ?? null) ? (float) $quote['settlementRate'] : null,
            'quotationType' => $isRate ? 'rate' : 'price',
        ];
        $results[] = ['requestedSymbol' => $symbol, 'symbol' => $requested, 'data' => $data];
    }

    if (!$results) {
        responderErroBrapi('A brapi não retornou cotação para este contrato futuro.', 404);
        return;
    }
    echo json_encode(
        ['results' => $results, 'requestedAt' => $body['requestedAt'] ?? null],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function historicoFuturos(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $range = trim((string) ($_GET['range'] ?? '3mo'));
    $intervalos = [
        '1d' => '-7 days',
        '7d' => '-7 days',
        '1mo' => '-1 month',
        '3mo' => '-3 months',
        '6mo' => '-6 months',
        '1y' => '-1 year',
    ];
    if ($symbol === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
        responderErroBrapi('Código do contrato futuro ausente ou inválido.', 400);
        return;
    }
    if (!isset($intervalos[$range])) {
        responderErroBrapi('Período de histórico de futuros inválido.', 400);
        return;
    }

    $fuso = new DateTimeZone('America/Sao_Paulo');
    $hoje = new DateTimeImmutable('today', $fuso);
    $inicio = $hoje->modify($intervalos[$range]);
    $resposta = brapiRequest('v2/futures/historical', [
        'symbol' => $symbol,
        'startDate' => $inicio->format('Y-m-d'),
        'endDate' => $hoje->format('Y-m-d'),
        'sortOrder' => 'asc',
    ]);
    if (!$resposta['ok']) {
        $status = (int) ($resposta['status'] ?? 502);
        $mensagem = $status === 403
            ? 'O histórico de contratos futuros exige o plano Pro da brapi.'
            : 'Não foi possível consultar o histórico do contrato futuro.';
        responderErroBrapi($mensagem, $status >= 400 && $status < 600 ? $status : 502);
        return;
    }

    $body = json_decode((string) $resposta['body'], true);
    $future = is_array($body) ? ($body['future'] ?? null) : null;
    if (!is_array($future)) {
        responderErroBrapi('A brapi retornou um histórico inválido para este contrato.');
        return;
    }
    $isRate = strtolower((string) ($future['quotationType'] ?? '')) === 'rate';
    $history = $future['history'] ?? [];
    if (!is_array($history)) $history = [];
    usort($history, static fn($a, $b) => (int) ($a['date'] ?? 0) <=> (int) ($b['date'] ?? 0));
    if ($range === '1d') $history = array_slice($history, -2);

    $points = [];
    foreach ($history as $point) {
        if (!is_array($point) || !is_numeric($point['date'] ?? null)) continue;
        $settlement = $point['settlement'] ?? null;
        $close = is_numeric($settlement) ? (float) $settlement
            : (is_numeric($point['close'] ?? null) ? (float) $point['close'] : null);
        if ($close === null || $close <= 0) continue;
        $points[] = [
            'date' => (int) $point['date'],
            'open' => null,
            // Em DI/DAP, high e low sao taxas percentuais, enquanto settlement e BRL.
            'high' => (!$isRate && is_numeric($point['high'] ?? null)) ? (float) $point['high'] : null,
            'low' => (!$isRate && is_numeric($point['low'] ?? null)) ? (float) $point['low'] : null,
            'close' => $close,
            'adjustedClose' => null,
            'volume' => is_numeric($point['volume'] ?? null) ? (int) $point['volume'] : null,
        ];
    }

    $results = [[
        'requestedSymbol' => $symbol,
        'symbol' => (string) ($future['symbol'] ?? $symbol),
        'data' => ['usedRange' => $range, 'usedInterval' => '1d', 'historicalDataPrice' => $points],
    ]];
    echo json_encode([
        'results' => $results,
        'requestedAt' => $body['requestedAt'] ?? null,
        'took' => $body['took'] ?? null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function patrimonioCarteirasAcaoController(): void
{

    global $conexao;
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $idUsuario = autenticar();
        $resultado = getCarteirasDoUsuario($conexao, $idUsuario);
        $idCarteira = $resultado['id_carteira'];

        $resultado = patrimonioCarteirasAcaoService(
            $conexao,
            $idUsuario,
            $idCarteira,
        );

        // O status HTTP é usado pelo Controller,
        // mas não será incluído no JSON.
        $statusHttp = $resultado['http_status'] ?? 200;

        unset($resultado['http_status']);

        http_response_code($statusHttp);

        echo json_encode(
            $resultado,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (Throwable $erro) {
        error_log(
            'Erro em patrimonioCarteirasController: ' .
                $erro->getMessage()
        );

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Erro interno ao consultar o patrimônio.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function listarFiisCatalogo(): void
{
    header("Content-Type: application/json; charset=UTF-8");

    $pagina = filter_var(
        $_GET["page"] ?? 1,
        FILTER_VALIDATE_INT
    );

    $limite = filter_var(
        $_GET["limit"] ?? 20,
        FILTER_VALIDATE_INT
    );

    if (
        $pagina === false ||
        $limite === false ||
        $pagina < 1 ||
        $limite < 1 ||
        $limite > 20
    ) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Paginação inválida."
        ]);
        return;
    }

    $resultado = brapiRequest("quote/list", [
        "subType" => "fii",
        "page" => $pagina,
        "limit" => $limite,
        "sortBy" => "volume",
        "sortOrder" => "desc"
    ]);

    if (!$resultado["ok"]) {
        http_response_code(502);
        echo json_encode([
            "success" => false,
            "message" => "Não foi possível consultar os FIIs."
        ]);
        return;
    }

    $dados = json_decode($resultado["body"], true);

    if (!is_array($dados)) {
        http_response_code(502);
        echo json_encode([
            "success" => false,
            "message" => "Resposta inválida da BRAPI."
        ]);
        return;
    }

    $ativos = [];

    foreach (($dados["stocks"] ?? []) as $ativo) {
        if (($ativo["subType"] ?? "") !== "fii") {
            continue;
        }

        $ativos[] = [
            "stock" => $ativo["stock"],
            "name" => $ativo["name"] ?? $ativo["stock"],
            "close" => $ativo["close"] ?? null,
            "change" => $ativo["change"] ?? null,
            "volume" => $ativo["volume"] ?? null,
            "logo" => $ativo["logo"] ?? null
        ];
    }

    $totalPaginas = (int) ($dados["totalPages"] ?? 1);

    echo json_encode([
        "success" => true,
        "stocks" => $ativos,
        "paginacao" => [
            "paginaAtual" => $pagina,
            "totalPaginas" => $totalPaginas,
            "temProximaPagina" =>
            (bool) ($dados["hasNextPage"] ?? false)
        ]
    ], JSON_UNESCAPED_UNICODE);
}
