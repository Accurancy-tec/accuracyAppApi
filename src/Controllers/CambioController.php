<?php

// Foreign currency endpoints (value of one unit in BRL), served by brapi like the rest of the
// market data: /v2/currency (quote), /v2/currency/available (pairs) and /v2/currency/historical
// (daily PTAX closing rates). The module needs a brapi plan that includes currencies
// (Startup or Pro); without it brapi answers 403 and the app gets a clear message.
//
// Responses reuse the stock JSON shapes so the Android list/details screens are shared.

require_once __DIR__ . '/BrapiController.php';

const CAMBIO_MOEDA_BASE = 'BRL';
const CAMBIO_CACHE_TTL = 45;             // seconds a quote is reused (protects the brapi quota)
const CAMBIO_CACHE_TTL_CATALOGO = 86400; // the list of pairs changes rarely
const CAMBIO_CACHE_TTL_HISTORICO = 1800; // PTAX is published once per business day
const CAMBIO_CACHE_STALE_MAX = 21600;    // old copy accepted when brapi fails or rate-limits us

/** Foreign currencies (value in BRL), in the order shown by the "rank" sort. */
const CAMBIO_MOEDAS_NOMES = [
    'USD' => 'Dólar americano', 'EUR' => 'Euro', 'GBP' => 'Libra esterlina', 'ARS' => 'Peso argentino',
    'CAD' => 'Dólar canadense', 'AUD' => 'Dólar australiano', 'JPY' => 'Iene japonês', 'CHF' => 'Franco suíço',
    'CNY' => 'Yuan chinês', 'MXN' => 'Peso mexicano', 'CLP' => 'Peso chileno', 'UYU' => 'Peso uruguaio',
    'COP' => 'Peso colombiano', 'PEN' => 'Sol peruano', 'BOB' => 'Boliviano', 'PYG' => 'Guarani paraguaio',
    'NZD' => 'Dólar neozelandês', 'SEK' => 'Coroa sueca', 'NOK' => 'Coroa norueguesa', 'DKK' => 'Coroa dinamarquesa',
    'ZAR' => 'Rand sul-africano', 'INR' => 'Rupia indiana', 'KRW' => 'Won sul-coreano', 'SGD' => 'Dólar de Singapura',
    'AED' => 'Dirham dos Emirados', 'ILS' => 'Novo shekel israelense', 'TRY' => 'Lira turca', 'PLN' => 'Zloty polonês',
];
const CAMBIO_MOEDAS_PADRAO = ['USD', 'EUR', 'GBP', 'ARS', 'CAD', 'AUD', 'JPY', 'CHF', 'CNY'];

function cambioCodigoValido(string $codigo): bool
{
    return preg_match('/^[A-Z]{3}$/', $codigo) === 1;
}

/** Removes Portuguese accents so "dolar" finds "Dólar" (no dependency on iconv/intl). */
function cambioSemAcento(string $texto): string
{
    return strtr($texto, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I',
        'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C',
    ]);
}

function cambioNumero($valor): ?float
{
    return is_numeric($valor) ? (float) $valor : null;
}

/* ------------------------------------------------------------------ brapi access + cache */

function cambioArquivoCache(string $endpoint, array $query): string
{
    $dir = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'accurancy_cambio_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    ksort($query);
    // The key has only endpoint and query: never the token.
    return $dir . DIRECTORY_SEPARATOR . sha1($endpoint . '?' . http_build_query($query)) . '.json';
}

function cambioCacheLer(string $arquivo, int $idadeMaxima): ?string
{
    if (!is_file($arquivo) || time() - (int) @filemtime($arquivo) > $idadeMaxima) {
        return null;
    }
    $conteudo = @file_get_contents($arquivo);
    return is_string($conteudo) && $conteudo !== '' ? $conteudo : null;
}

/**
 * brapi request with a short cache. A copy up to 6 hours old is used when brapi is rate-limiting
 * or failing (not for 401/403, which are token/plan problems the person must see).
 * Returns ['ok'=>bool, 'status'=>int, 'body'=>?string].
 */
function cambioBrapi(string $endpoint, array $query = [], int $ttl = CAMBIO_CACHE_TTL): array
{
    $arquivo = cambioArquivoCache($endpoint, $query);
    $recente = cambioCacheLer($arquivo, $ttl);
    if ($recente !== null) {
        return ['ok' => true, 'status' => 200, 'body' => $recente];
    }

    $resposta = brapiRequest($endpoint, $query);
    if (!empty($resposta['ok'])) {
        @file_put_contents($arquivo, (string) $resposta['body'], LOCK_EX);
        return ['ok' => true, 'status' => (int) ($resposta['status'] ?? 200), 'body' => (string) $resposta['body']];
    }

    $status = (int) ($resposta['status'] ?? 502);
    if ($status !== 401 && $status !== 403) {
        $antigo = cambioCacheLer($arquivo, CAMBIO_CACHE_STALE_MAX);
        if ($antigo !== null) {
            return ['ok' => true, 'status' => 200, 'body' => $antigo];
        }
    }
    return ['ok' => false, 'status' => $status, 'body' => $resposta['body'] ?? null];
}

/** Sends the error the person should see for a failed brapi answer. */
function cambioResponderErro(array $resposta, string $mensagemPadrao): void
{
    $status = (int) ($resposta['status'] ?? 502);
    if ($status === 403) {
        responderErroBrapi('O plano da brapi desta API não inclui cotações de moedas. Confira o plano em brapi.dev/dashboard.', 502);
    } elseif ($status === 401) {
        responderErroBrapi('O token da brapi desta API é inválido ou expirou. Atualize o BRAPI_TOKEN.', 502);
    } elseif ($status === 429) {
        responderErroBrapi('A brapi limitou as consultas agora. Tente novamente em instantes.', 429);
    } else {
        responderErroBrapi($mensagemPadrao, 502);
    }
}

/* ------------------------------------------------------------------- catalog, quotes */

/** Codes the app offers: known currencies that brapi can quote against BRL, in display order. */
function cambioCatalogo(): array
{
    $disponiveis = [];
    $resposta = cambioBrapi('v2/currency/available', [], CAMBIO_CACHE_TTL_CATALOGO);
    if ($resposta['ok']) {
        $json = json_decode((string) $resposta['body'], true);
        $lista = is_array($json) && is_array($json['currencies'] ?? null) ? $json['currencies'] : [];
        foreach ($lista as $item) {
            $par = is_array($item) ? strtoupper(trim((string) ($item['name'] ?? ''))) : '';
            if (preg_match('/^([A-Z]{3})-' . CAMBIO_MOEDA_BASE . '$/', $par, $m)) {
                $disponiveis[$m[1]] = true;
            }
        }
    }

    $codigos = [];
    foreach (array_keys(CAMBIO_MOEDAS_NOMES) as $codigo) {
        if ($disponiveis ? isset($disponiveis[$codigo]) : in_array($codigo, CAMBIO_MOEDAS_PADRAO, true)) {
            $codigos[] = $codigo;
        }
    }
    return $codigos ?: CAMBIO_MOEDAS_PADRAO;
}

/** ISO-8601 UTC time of a brapi quote (timestamp in seconds/ms, or "Y-m-d H:i:s" in Brasília time). */
function cambioHora(array $item): ?string
{
    $ts = $item['updatedAtTimestamp'] ?? null;
    if (is_numeric($ts) && (float) $ts > 0) {
        $ts = (float) $ts;
        return gmdate('Y-m-d\TH:i:s\Z', (int) ($ts > 1e11 ? $ts / 1000 : $ts));
    }
    $data = trim((string) ($item['updatedAtDate'] ?? ''));
    if ($data !== '') {
        try {
            $momento = new DateTimeImmutable($data, new DateTimeZone('America/Sao_Paulo'));
            return $momento->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            return null;
        }
    }
    return null;
}

/** Parses the /v2/currency payload into a map CODE => quote. Pairs without a positive price are skipped. */
function cambioInterpretarCotacoes(string $body): array
{
    $json = json_decode($body, true);
    $lista = is_array($json) && is_array($json['currency'] ?? null) ? $json['currency'] : [];
    $mapa = [];
    foreach ($lista as $item) {
        if (!is_array($item)) continue;
        $codigo = strtoupper(trim((string) ($item['fromCurrency'] ?? '')));
        if (!cambioCodigoValido($codigo) || strtoupper(trim((string) ($item['toCurrency'] ?? ''))) !== CAMBIO_MOEDA_BASE) continue;
        $preco = cambioNumero($item['bidPrice'] ?? null);
        if ($preco === null || $preco <= 0) continue; // no quote, no trading

        $mapa[$codigo] = [
            'preco' => $preco,
            'variacaoPercentual' => cambioNumero($item['percentageChange'] ?? null),
            'alta' => cambioNumero($item['high'] ?? null),
            'baixa' => cambioNumero($item['low'] ?? null),
            'hora' => cambioHora($item),
        ];
    }
    return $mapa;
}

/**
 * Quotes a list of codes. Returns ['mapa' => CODE => quote, 'erro' => ?failed response].
 * When brapi rejects the whole call because of one unknown pair, the codes are retried one by one.
 */
function cambioCotacoes(array $codigos): array
{
    $codigos = array_values(array_unique(array_filter($codigos, 'cambioCodigoValido')));
    if (!$codigos) {
        return ['mapa' => [], 'erro' => null];
    }
    $pares = static fn(array $lista) => implode(',', array_map(static fn($c) => $c . '-' . CAMBIO_MOEDA_BASE, $lista));

    $resposta = cambioBrapi('v2/currency', ['currency' => $pares($codigos)]);
    if ($resposta['ok']) {
        return ['mapa' => cambioInterpretarCotacoes((string) $resposta['body']), 'erro' => null];
    }

    $status = (int) $resposta['status'];
    if (count($codigos) > 1 && ($status === 400 || $status === 404)) {
        $mapa = [];
        foreach ($codigos as $codigo) {
            $unica = cambioBrapi('v2/currency', ['currency' => $pares([$codigo])]);
            if ($unica['ok']) {
                $mapa += cambioInterpretarCotacoes((string) $unica['body']);
            }
        }
        return ['mapa' => $mapa, 'erro' => $mapa ? null : $resposta];
    }
    return ['mapa' => [], 'erro' => $resposta];
}

/**
 * Daily candles from the PTAX closing rates (one value per business day), so each open is the
 * previous close and high/low are the larger/smaller of open and close. One candle per day.
 */
function cambioPontosHistorico(array $observacoes): array
{
    $porDia = [];
    foreach ($observacoes as $obs) {
        if (!is_array($obs)) continue;
        $data = trim((string) ($obs['date'] ?? ''));
        $valor = cambioNumero($obs['value'] ?? null);
        if ($valor === null || $valor <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}/', $data) !== 1) continue;
        $dia = substr($data, 0, 10);
        $ts = strtotime($dia . ' 12:00:00 UTC'); // noon UTC: the same calendar day in Brasília time
        if ($ts === false) continue;
        $porDia[$dia] = ['date' => $ts, 'close' => $valor];
    }
    ksort($porDia);

    $pontos = [];
    $anterior = null;
    foreach ($porDia as $dia) {
        $abertura = $anterior ?? $dia['close'];
        $pontos[] = [
            'date' => $dia['date'],
            'open' => $abertura,
            'high' => max($abertura, $dia['close']),
            'low' => min($abertura, $dia['close']),
            'close' => $dia['close'],
            'adjustedClose' => $dia['close'],
            'volume' => null,
        ];
        $anterior = $dia['close'];
    }
    return $pontos;
}

/** Fetches the history of one pair; $query carries the dates. Returns candles or null on failure. */
function cambioBuscarHistorico(string $codigo, array $query): ?array
{
    $resposta = cambioBrapi('v2/currency/historical',
        array_merge(['currency' => $codigo . '-' . CAMBIO_MOEDA_BASE], $query), CAMBIO_CACHE_TTL_HISTORICO);
    if (!$resposta['ok']) {
        return null;
    }
    $json = json_decode((string) $resposta['body'], true);
    $resultado = is_array($json) && is_array($json['results'] ?? null) ? ($json['results'][0] ?? null) : null;
    if (!is_array($resultado) || !is_array($resultado['observations'] ?? null)) {
        return null;
    }
    return cambioPontosHistorico($resultado['observations']);
}

/* ------------------------------------------------------------------------ HTTP handlers */

function listarMoedas(): void
{
    header('Content-Type: application/json; charset=UTF-8');

    $pagina = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
    $limite = filter_var($_GET['limit'] ?? '20', FILTER_VALIDATE_INT);
    $busca = strtoupper(trim((string) ($_GET['search'] ?? '')));
    $ordenarPor = trim((string) ($_GET['sortBy'] ?? 'rank'));
    $ordem = strtolower(trim((string) ($_GET['sortOrder'] ?? 'desc')));

    if ($pagina === false || $pagina === null || $limite === false || $limite === null
        || $pagina < 1 || $limite < 1 || $limite > 20) {
        responderErroBrapi('Página inválida ou limite fora do intervalo (1 a 20).', 400);
        return;
    }
    if (!in_array($ordenarPor, ['rank', 'name'], true) || !in_array($ordem, ['asc', 'desc'], true)) {
        responderErroBrapi('Critério de ordenação inválido.', 400);
        return;
    }
    if ($busca !== '' && !preg_match('/^[A-Z0-9 ._-]{1,30}$/', $busca)) {
        responderErroBrapi('Termo de pesquisa inválido.', 400);
        return;
    }

    $codigos = cambioCatalogo();
    $cotacoes = cambioCotacoes($codigos);
    if (!$cotacoes['mapa']) {
        cambioResponderErro($cotacoes['erro'] ?? ['status' => 502],
            'Não foi possível consultar as cotações de moedas agora. Tente novamente em instantes.');
        return;
    }

    $itens = [];
    foreach ($codigos as $codigo) {
        if (!isset($cotacoes['mapa'][$codigo])) continue;
        $nome = CAMBIO_MOEDAS_NOMES[$codigo] ?? $codigo;
        if ($busca !== '' && stripos($codigo, $busca) === false
            && stripos(cambioSemAcento($nome), cambioSemAcento($busca)) === false) {
            continue;
        }
        $itens[] = ['codigo' => $codigo, 'nome' => $nome, 'cotacao' => $cotacoes['mapa'][$codigo]];
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
            'logo' => null, // brapi has no currency icons; the app shows the code
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

function getCurrencyQuote(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $simbolo = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    if ($simbolo === '' || !cambioCodigoValido($simbolo)) {
        responderErroBrapi('Código da moeda ausente ou inválido.', 400);
        return;
    }

    $cotacoes = cambioCotacoes([$simbolo]);
    $cotacao = $cotacoes['mapa'][$simbolo] ?? null;
    if ($cotacao === null) {
        $erro = $cotacoes['erro'];
        $status = (int) ($erro['status'] ?? 0);
        if ($erro !== null && in_array($status, [401, 403, 429], true)) {
            cambioResponderErro($erro, '');
        } else {
            responderErroBrapi('Não encontrei cotação para esta moeda agora.', 404);
        }
        return;
    }

    $data = [
        'symbol' => $simbolo,
        'shortName' => $simbolo,
        'longName' => CAMBIO_MOEDAS_NOMES[$simbolo] ?? $simbolo,
        'regularMarketVolume' => null,
        'regularMarketPrice' => $cotacao['preco'],
        'regularMarketChangePercent' => $cotacao['variacaoPercentual'],
        'regularMarketTime' => $cotacao['hora'],
        'regularMarketOpen' => null,
        'regularMarketDayHigh' => $cotacao['alta'],
        'fiftyTwoWeekHigh' => null,
        'fiftyTwoWeekLow' => null,
        'currency' => CAMBIO_MOEDA_BASE,
    ];

    echo json_encode(
        ['results' => [['requestedSymbol' => $simbolo, 'symbol' => $simbolo, 'data' => $data]]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function historicoMoedas(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $simbolo = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $periodo = trim((string) ($_GET['range'] ?? '3mo'));
    // "Hoje" and 7 days need a window wide enough to hold business days (no intraday data).
    $janelas = [
        '1d' => '-10 days', '7d' => '-7 days', '1mo' => '-1 month', '3mo' => '-3 months',
        '6mo' => '-6 months', '1y' => '-1 year', '2y' => '-2 years', '5y' => '-5 years',
    ];

    if ($simbolo === '' || !cambioCodigoValido($simbolo)) {
        responderErroBrapi('Código da moeda ausente ou inválido.', 400);
        return;
    }
    if (!isset($janelas[$periodo])) {
        responderErroBrapi('Período de histórico inválido.', 400);
        return;
    }

    $fuso = new DateTimeZone('America/Sao_Paulo');
    $hoje = new DateTimeImmutable('today', $fuso);
    $datas = [
        'startDate' => $hoje->modify($janelas[$periodo])->format('Y-m-d'),
        'endDate' => $hoje->format('Y-m-d'),
    ];

    // First with a large limit (the default is 365 observations); if brapi refuses it, without.
    $pontos = cambioBuscarHistorico($simbolo, $datas + ['limit' => 1500]);
    if ($pontos === null) {
        $pontos = cambioBuscarHistorico($simbolo, $datas);
    }
    if ($pontos === null) {
        responderErroBrapi('Não foi possível consultar o histórico agora. Tente novamente em instantes.', 502);
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
