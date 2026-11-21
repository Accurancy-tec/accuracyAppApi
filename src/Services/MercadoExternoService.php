<?php

// Free market data for cryptocurrencies and foreign currencies, quoted in BRL.
// Source: AwesomeAPI (https://economia.awesomeapi.com.br). It works without a key (the provider
// then caches for about 1 minute); an optional key in AWESOMEAPI_TOKEN is sent in the x-api-key
// header and never logged. brapi only serves crypto/currency on paid plans (HTTP 403 on the free one).
//
// Responses reuse the same JSON shapes as the stock endpoints so the Android list/details
// screens are shared.

const EXT_BASE_URL_PADRAO = 'https://economia.awesomeapi.com.br';
const EXT_MOEDA_BASE = 'BRL';
const EXT_CACHE_TTL = 45;            // seconds a quote is reused (protects the provider limit)
const EXT_CACHE_TTL_CATALOGO = 86400; // the list of available pairs changes rarely
const EXT_CACHE_STALE_MAX = 21600;   // old copy accepted when the provider rate-limits us
const EXT_MAX_DIAS_HISTORICO = 360;  // provider limit for the daily history

/** Known crypto codes with display names, in the order shown by the "rank" sort. */
const EXT_CRIPTO_NOMES = [
    'BTC' => 'Bitcoin', 'ETH' => 'Ethereum', 'XRP' => 'XRP', 'SOL' => 'Solana', 'BNB' => 'BNB',
    'DOGE' => 'Dogecoin', 'ADA' => 'Cardano', 'LTC' => 'Litecoin', 'DOT' => 'Polkadot',
    'AVAX' => 'Avalanche', 'LINK' => 'Chainlink', 'MATIC' => 'Polygon', 'BCH' => 'Bitcoin Cash',
    'XLM' => 'Stellar', 'TRX' => 'TRON', 'UNI' => 'Uniswap', 'ATOM' => 'Cosmos', 'SHIB' => 'Shiba Inu',
    'ETC' => 'Ethereum Classic', 'DASH' => 'Dash',
];
/** Crypto pairs assumed available when the provider catalog cannot be read. */
const EXT_CRIPTO_PADRAO = ['BTC', 'ETH', 'XRP', 'DOGE', 'LTC'];

/** Foreign currencies (value in BRL), in the order shown by the "rank" sort. */
const EXT_MOEDAS_NOMES = [
    'USD' => 'Dólar americano', 'EUR' => 'Euro', 'GBP' => 'Libra esterlina', 'ARS' => 'Peso argentino',
    'CAD' => 'Dólar canadense', 'AUD' => 'Dólar australiano', 'JPY' => 'Iene japonês', 'CHF' => 'Franco suíço',
    'CNY' => 'Yuan chinês', 'MXN' => 'Peso mexicano', 'CLP' => 'Peso chileno', 'UYU' => 'Peso uruguaio',
    'COP' => 'Peso colombiano', 'PEN' => 'Sol peruano', 'BOB' => 'Boliviano', 'PYG' => 'Guarani paraguaio',
    'NZD' => 'Dólar neozelandês', 'SEK' => 'Coroa sueca', 'NOK' => 'Coroa norueguesa', 'DKK' => 'Coroa dinamarquesa',
    'ZAR' => 'Rand sul-africano', 'INR' => 'Rupia indiana', 'KRW' => 'Won sul-coreano', 'SGD' => 'Dólar de Singapura',
    'AED' => 'Dirham dos Emirados', 'ILS' => 'Novo shekel israelense', 'TRY' => 'Lira turca', 'PLN' => 'Zloty polonês',
];
const EXT_MOEDAS_PADRAO = ['USD', 'EUR', 'GBP', 'ARS', 'CAD', 'AUD', 'JPY', 'CHF', 'CNY'];

const EXT_PADRAO_CODIGO = '/^[A-Z0-9]{2,10}$/';

function extCodigoValido(string $codigo): bool
{
    return preg_match(EXT_PADRAO_CODIGO, $codigo) === 1;
}

/** Name table of a market type: "cripto" or "moeda". */
function extNomes(string $tipo): array
{
    return $tipo === 'cripto' ? EXT_CRIPTO_NOMES : EXT_MOEDAS_NOMES;
}

/** Removes Portuguese accents so "dolar" finds "Dólar" (no dependency on iconv/intl). */
function extSemAcento(string $texto): string
{
    return strtr($texto, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I',
        'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C',
    ]);
}

function extResponderErro(string $mensagem, int $status = 502): void
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'sucesso' => false,
        'message' => $mensagem,
        'mensagem' => $mensagem,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ------------------------------------------------------------------ transport, cache, 429 */

if (!function_exists('extHttpGet')) {
    /** One HTTP GET. Returns body (or false), status, cURL error and Retry-After seconds. */
    function extHttpGet(string $url, ?string $token): array
    {
        $retryAfter = 0;
        $cabecalhos = ['Accept: application/json'];
        if ($token !== null && $token !== '') {
            $cabecalhos[] = 'x-api-key: ' . $token;
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $cabecalhos,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $linha) use (&$retryAfter) {
                if (stripos($linha, 'retry-after:') === 0) {
                    $retryAfter = (int) trim(substr($linha, 12));
                }
                return strlen($linha);
            },
        ]);
        $body = curl_exec($curl);
        $erro = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return ['body' => $body, 'status' => $status, 'curl_error' => $erro, 'retry_after' => $retryAfter];
    }
}

/** Cache file for one request. The key uses only path and query: never the token. */
function extArquivoCache(string $caminho, array $query): string
{
    $dir = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'accurancy_ext_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    ksort($query);
    return $dir . DIRECTORY_SEPARATOR . sha1($caminho . '?' . http_build_query($query)) . '.json';
}

function extCacheLer(string $arquivo, int $idadeMaxima): ?string
{
    if (!is_file($arquivo)) {
        return null;
    }
    if (time() - (int) @filemtime($arquivo) > $idadeMaxima) {
        return null;
    }
    $conteudo = @file_get_contents($arquivo);
    return is_string($conteudo) && $conteudo !== '' ? $conteudo : null;
}

/**
 * GET against the provider with a short cache, one retry on HTTP 429 and a stale copy as last resort.
 * Returns ['ok'=>bool, 'status'=>int, 'body'=>?string].
 */
function extRequest(string $caminho, array $query = [], int $ttl = EXT_CACHE_TTL): array
{
    $base = getenv('AWESOMEAPI_BASE_URL');
    $base = is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : EXT_BASE_URL_PADRAO;
    $token = getenv('AWESOMEAPI_TOKEN');
    $token = is_string($token) && trim($token) !== '' ? trim($token) : null;

    $url = $base . '/' . ltrim($caminho, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $arquivo = extArquivoCache($caminho, $query);
    $recente = extCacheLer($arquivo, $ttl);
    if ($recente !== null) {
        return ['ok' => true, 'status' => 200, 'body' => $recente];
    }

    $resposta = extHttpGet($url, $token);
    if ($resposta['body'] !== false && $resposta['status'] === 429) {
        error_log('AwesomeAPI HTTP 429 caminho=' . $caminho . ' (nova tentativa)');
        sleep(max(1, min(2, (int) $resposta['retry_after'])));
        $resposta = extHttpGet($url, $token);
    }

    if ($resposta['body'] === false) {
        error_log('AwesomeAPI cURL error: ' . $resposta['curl_error']);
        return ['ok' => false, 'status' => 502, 'body' => null];
    }
    $status = $resposta['status'];
    if ($status < 200 || $status >= 300) {
        error_log('AwesomeAPI HTTP status: ' . $status . ' caminho=' . $caminho);
        if ($status === 429) {
            $antigo = extCacheLer($arquivo, EXT_CACHE_STALE_MAX);
            if ($antigo !== null) {
                return ['ok' => true, 'status' => 200, 'body' => $antigo];
            }
        }
        return ['ok' => false, 'status' => $status, 'body' => $resposta['body']];
    }

    @file_put_contents($arquivo, (string) $resposta['body'], LOCK_EX);
    return ['ok' => true, 'status' => $status, 'body' => (string) $resposta['body']];
}

/* ------------------------------------------------------------------------- catalog, quotes */

/** Codes of the market type that the provider can quote against BRL, in display order. */
function extCatalogo(string $tipo): array
{
    $nomes = extNomes($tipo);
    $padrao = $tipo === 'cripto' ? EXT_CRIPTO_PADRAO : EXT_MOEDAS_PADRAO;

    $disponiveis = [];
    $resposta = extRequest('json/available', [], EXT_CACHE_TTL_CATALOGO);
    if ($resposta['ok']) {
        $json = json_decode((string) $resposta['body'], true);
        if (is_array($json)) {
            foreach ($json as $chave => $valor) {
                $par = is_string($chave) ? $chave : (is_string($valor) ? $valor : '');
                if (preg_match('/^([A-Z0-9]{2,10})-' . EXT_MOEDA_BASE . '$/', strtoupper($par), $m)) {
                    $disponiveis[$m[1]] = true;
                }
            }
        }
    }

    $codigos = [];
    foreach (array_keys($nomes) as $codigo) {
        if ($disponiveis ? isset($disponiveis[$codigo]) : in_array($codigo, $padrao, true)) {
            $codigos[] = $codigo;
        }
    }
    return $codigos ?: $padrao;
}

function extNumero($valor): ?float
{
    return is_numeric($valor) ? (float) $valor : null;
}

/** Parses the "last" payload into a map CODE => quote. Pairs without a positive price are skipped. */
function extInterpretarCotacoes(string $body): array
{
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [];
    }
    $mapa = [];
    foreach ($json as $item) {
        if (!is_array($item)) continue;
        $codigo = strtoupper(trim((string) ($item['code'] ?? '')));
        if ($codigo === '' || strtoupper(trim((string) ($item['codein'] ?? ''))) !== EXT_MOEDA_BASE) continue;
        $preco = extNumero($item['bid'] ?? null);
        if ($preco === null || $preco <= 0) continue; // no quote, no trading

        $hora = is_numeric($item['timestamp'] ?? null) ? gmdate('Y-m-d\TH:i:s\Z', (int) $item['timestamp']) : null;
        $mapa[$codigo] = [
            'preco' => $preco,
            'variacaoPercentual' => extNumero($item['pctChange'] ?? null),
            'alta' => extNumero($item['high'] ?? null),
            'baixa' => extNumero($item['low'] ?? null),
            'hora' => $hora,
        ];
    }
    return $mapa;
}

/** Quotes a list of codes. Pairs the provider rejects are skipped. Returns a map CODE => quote. */
function extCotacoes(array $codigos): array
{
    $codigos = array_values(array_unique(array_filter($codigos, 'extCodigoValido')));
    if (!$codigos) {
        return [];
    }
    $pares = array_map(static fn($c) => $c . '-' . EXT_MOEDA_BASE, $codigos);
    $resposta = extRequest('json/last/' . implode(',', $pares));
    if ($resposta['ok']) {
        return extInterpretarCotacoes((string) $resposta['body']);
    }

    // The provider answers 404 for the whole call when one pair is unknown: retry one by one.
    $mapa = [];
    if (count($codigos) > 1) {
        foreach ($codigos as $codigo) {
            $unica = extRequest('json/last/' . $codigo . '-' . EXT_MOEDA_BASE);
            if ($unica['ok']) {
                $mapa += extInterpretarCotacoes((string) $unica['body']);
            }
        }
    }
    return $mapa;
}

/**
 * Daily candles. The provider gives one quote per day, so the open is the previous close and
 * high/low are widened to contain open and close. Sorted by date, one candle per day.
 */
function extHistoricoDiario(string $codigo, int $dias): ?array
{
    $dias = max(1, min(EXT_MAX_DIAS_HISTORICO, $dias));
    $resposta = extRequest('json/daily/' . $codigo . '-' . EXT_MOEDA_BASE . '/' . $dias);
    if (!$resposta['ok']) {
        return null;
    }
    $json = json_decode((string) $resposta['body'], true);
    if (!is_array($json)) {
        return null;
    }

    $porDia = [];
    foreach ($json as $item) {
        if (!is_array($item) || !is_numeric($item['timestamp'] ?? null)) continue;
        $fechamento = extNumero($item['bid'] ?? null);
        if ($fechamento === null || $fechamento <= 0) continue;
        $data = (int) $item['timestamp'];
        $chave = gmdate('Y-m-d', $data);
        $porDia[$chave] = [
            'date' => $data,
            'close' => $fechamento,
            'high' => extNumero($item['high'] ?? null),
            'low' => extNumero($item['low'] ?? null),
        ];
    }
    ksort($porDia);

    $pontos = [];
    $anterior = null;
    foreach ($porDia as $dia) {
        $abertura = $anterior ?? $dia['close'];
        $maxima = max($dia['high'] ?? $dia['close'], $abertura, $dia['close']);
        $minima = min($dia['low'] ?? $dia['close'], $abertura, $dia['close']);
        $pontos[] = [
            'date' => $dia['date'],
            'open' => $abertura,
            'high' => $maxima,
            'low' => $minima,
            'close' => $dia['close'],
            'adjustedClose' => $dia['close'],
            'volume' => null,
        ];
        $anterior = $dia['close'];
    }
    return $pontos;
}

/* ------------------------------------------------------------------------ HTTP handlers */

function extListar(string $tipo): void
{
    header('Content-Type: application/json; charset=UTF-8');

    $pagina = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
    $limite = filter_var($_GET['limit'] ?? '20', FILTER_VALIDATE_INT);
    $busca = strtoupper(trim((string) ($_GET['search'] ?? '')));
    $ordenarPor = trim((string) ($_GET['sortBy'] ?? 'rank'));
    $ordem = strtolower(trim((string) ($_GET['sortOrder'] ?? 'desc')));
    $rotulo = $tipo === 'cripto' ? 'criptomoedas' : 'moedas';

    if ($pagina === false || $pagina === null || $limite === false || $limite === null
        || $pagina < 1 || $limite < 1 || $limite > 20) {
        extResponderErro('Página inválida ou limite fora do intervalo (1 a 20).', 400);
        return;
    }
    if (!in_array($ordenarPor, ['rank', 'name'], true) || !in_array($ordem, ['asc', 'desc'], true)) {
        extResponderErro('Critério de ordenação inválido.', 400);
        return;
    }
    if ($busca !== '' && !preg_match('/^[A-Z0-9 ._-]{1,30}$/', $busca)) {
        extResponderErro('Termo de pesquisa inválido.', 400);
        return;
    }

    $nomes = extNomes($tipo);
    $codigos = extCatalogo($tipo);
    $cotacoes = extCotacoes($codigos);
    if (!$cotacoes) {
        extResponderErro('Não foi possível consultar as cotações de ' . $rotulo . ' agora. Tente novamente em instantes.', 502);
        return;
    }

    $itens = [];
    foreach ($codigos as $codigo) {
        if (!isset($cotacoes[$codigo])) continue;
        $nome = $nomes[$codigo] ?? $codigo;
        if ($busca !== '' && stripos($codigo, $busca) === false
            && stripos(extSemAcento($nome), extSemAcento($busca)) === false) {
            continue;
        }
        $itens[] = ['codigo' => $codigo, 'nome' => $nome, 'cotacao' => $cotacoes[$codigo]];
    }

    // "rank" keeps the catalog order (largest first); "name" is alphabetical by name.
    if ($ordenarPor === 'name') {
        usort($itens, static fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
        if ($ordem === 'desc') $itens = array_reverse($itens);
    } elseif ($ordem === 'asc') {
        $itens = array_reverse($itens);
    }

    $total = count($itens);
    $totalPaginas = max(1, (int) ceil($total / $limite));
    $paginaAtual = min($pagina, $totalPaginas);
    $recorte = array_slice($itens, ($paginaAtual - 1) * $limite, $limite);

    $ativos = [];
    foreach ($recorte as $item) {
        $ativos[] = [
            'stock' => $item['codigo'],
            'name' => $item['nome'],
            'close' => $item['cotacao']['preco'],
            'change' => $item['cotacao']['variacaoPercentual'],
            'volume' => null,
            'marketCap' => null,
            'logo' => null, // the provider has no icons; the app shows the code
        ];
    }

    echo json_encode([
        'success' => true,
        'stocks' => $ativos,
        'paginacao' => [
            'paginaAtual' => $paginaAtual,
            'totalPaginas' => $totalPaginas,
            'totalAtivos' => $total,
            'temProximaPagina' => $paginaAtual < $totalPaginas,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function extCotacaoAtivo(string $tipo): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $simbolo = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $rotulo = $tipo === 'cripto' ? 'criptomoeda' : 'moeda';
    if ($simbolo === '' || !extCodigoValido($simbolo)) {
        extResponderErro('Código da ' . $rotulo . ' ausente ou inválido.', 400);
        return;
    }

    $cotacao = extCotacoes([$simbolo])[$simbolo] ?? null;
    if ($cotacao === null) {
        extResponderErro('Não encontrei cotação para esta ' . $rotulo . ' agora.', 404);
        return;
    }

    $data = [
        'symbol' => $simbolo,
        'shortName' => $simbolo,
        'longName' => extNomes($tipo)[$simbolo] ?? $simbolo,
        'regularMarketVolume' => null,
        'regularMarketPrice' => $cotacao['preco'],
        'regularMarketChangePercent' => $cotacao['variacaoPercentual'],
        'regularMarketTime' => $cotacao['hora'],
        'regularMarketOpen' => null,
        'regularMarketDayHigh' => $cotacao['alta'],
        'fiftyTwoWeekHigh' => null,
        'fiftyTwoWeekLow' => null,
        'currency' => EXT_MOEDA_BASE,
    ];

    echo json_encode(
        ['results' => [['requestedSymbol' => $simbolo, 'symbol' => $simbolo, 'data' => $data]]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function extHistorico(string $tipo): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $simbolo = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $periodo = trim((string) ($_GET['range'] ?? '3mo'));
    $rotulo = $tipo === 'cripto' ? 'criptomoeda' : 'moeda';
    // Number of daily candles per range ("Hoje" shows the last two days: no intraday data).
    $dias = [
        '1d' => 5, '7d' => 7, '1mo' => 30, '3mo' => 90, '6mo' => 180,
        '1y' => 360, '2y' => 360, '5y' => 360,
    ];

    if ($simbolo === '' || !extCodigoValido($simbolo)) {
        extResponderErro('Código da ' . $rotulo . ' ausente ou inválido.', 400);
        return;
    }
    if (!isset($dias[$periodo])) {
        extResponderErro('Período de histórico inválido.', 400);
        return;
    }

    $pontos = extHistoricoDiario($simbolo, $dias[$periodo]);
    if ($pontos === null) {
        extResponderErro('Não foi possível consultar o histórico agora. Tente novamente em instantes.', 502);
        return;
    }
    if ($periodo === '1d') {
        $pontos = array_slice($pontos, -2);
    }

    echo json_encode([
        'results' => [[
            'requestedSymbol' => $simbolo,
            'symbol' => $simbolo,
            'data' => [
                'usedRange' => $periodo,
                'usedInterval' => '1d',
                'historicalDataPrice' => $pontos,
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
