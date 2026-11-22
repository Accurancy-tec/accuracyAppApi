<?php

// Cryptocurrency market endpoints (brapi /v2/crypto). Responses reuse the same JSON
// shapes as the stock endpoints so the Android list/details screens can be shared.

require_once __DIR__ . '/BrapiController.php';

const CRIPTO_MOEDA = 'BRL';
const CRIPTO_PADRAO_MOEDA = '/^[A-Z0-9]{1,15}$/';

function criptoSimboloValido(string $simbolo): bool
{
    return preg_match(CRIPTO_PADRAO_MOEDA, $simbolo) === 1;
}

/** Quotes a list of coins. Returns a map SYMBOL => coin item; coins brapi rejects are skipped. */
function criptoBuscarCotacoes(array $simbolos): array
{
    $simbolos = array_values(array_filter($simbolos, 'criptoSimboloValido'));
    if (!$simbolos) {
        return [];
    }

    $extrair = static function (array $resposta): ?array {
        if (!$resposta['ok']) {
            return null;
        }
        $json = json_decode((string) $resposta['body'], true);
        return is_array($json) && isset($json['coins']) && is_array($json['coins']) ? $json['coins'] : null;
    };

    $moedas = $extrair(brapiRequest('v2/crypto', ['coin' => implode(',', $simbolos), 'currency' => CRIPTO_MOEDA]));

    // brapi answers 400 for the whole call when one symbol is unavailable: retry one by one.
    if ($moedas === null && count($simbolos) > 1) {
        $moedas = [];
        foreach ($simbolos as $simbolo) {
            $unica = $extrair(brapiRequest('v2/crypto', ['coin' => $simbolo, 'currency' => CRIPTO_MOEDA]));
            if ($unica) {
                foreach ($unica as $item) {
                    $moedas[] = $item;
                }
            }
        }
    }

    $mapa = [];
    foreach ($moedas ?? [] as $item) {
        if (!is_array($item)) continue;
        $simbolo = strtoupper(trim((string) ($item['coin'] ?? '')));
        if ($simbolo !== '') {
            $mapa[$simbolo] = $item;
        }
    }
    return $mapa;
}

function criptoLogoSeguro($url): ?string
{
    $url = is_string($url) ? trim($url) : '';
    return $url !== '' && preg_match('#^https://[^\s]+$#i', $url) === 1 ? $url : null;
}

function listarCriptomoedas(): void
{
    header('Content-Type: application/json; charset=UTF-8');

    $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? '20', FILTER_VALIDATE_INT);
    $search = strtoupper(trim((string) ($_GET['search'] ?? '')));
    $sortBy = trim((string) ($_GET['sortBy'] ?? 'rank'));
    $sortOrder = strtolower(trim((string) ($_GET['sortOrder'] ?? 'desc')));

    if ($page === false || $page === null || $limit === false || $limit === null
        || $page < 1 || $limit < 1 || $limit > 20) {
        responderErroBrapi('Página inválida ou limite fora do intervalo (1 a 20).', 400);
        return;
    }
    if (!in_array($sortBy, ['rank', 'name'], true) || !in_array($sortOrder, ['asc', 'desc'], true)) {
        responderErroBrapi('Critério de ordenação inválido.', 400);
        return;
    }
    if ($search !== '' && !preg_match('/^[A-Z0-9 ._-]{1,30}$/', $search)) {
        responderErroBrapi('Termo de pesquisa inválido.', 400);
        return;
    }

    $query = $search !== '' ? ['search' => $search] : [];
    $resposta = brapiRequest('v2/crypto/available', $query);
    if (!$resposta['ok']) {
        $status = (int) ($resposta['status'] ?? 502);
        responderErroBrapi('Não foi possível listar as criptomoedas na brapi.',
            $status >= 400 && $status < 600 && $status !== 401 ? $status : 502);
        return;
    }
    $dados = json_decode((string) $resposta['body'], true);
    if (!is_array($dados) || !isset($dados['coins']) || !is_array($dados['coins'])) {
        responderErroBrapi('Resposta inválida da brapi ao listar criptomoedas.');
        return;
    }

    $simbolos = [];
    foreach ($dados['coins'] as $coin) {
        $simbolo = strtoupper(trim((string) $coin));
        if (criptoSimboloValido($simbolo)) {
            $simbolos[$simbolo] = true;
        }
    }
    $simbolos = array_keys($simbolos);

    // "rank" keeps the provider order (largest coins first); "name" is alphabetical by symbol.
    if ($sortBy === 'name') {
        usort($simbolos, 'strnatcasecmp');
        if ($sortOrder === 'desc') $simbolos = array_reverse($simbolos);
    } elseif ($sortOrder === 'asc') {
        $simbolos = array_reverse($simbolos);
    }

    $total = count($simbolos);
    $totalPaginas = max(1, (int) ceil($total / $limit));
    $pagina = min($page, $totalPaginas);
    $paginaSimbolos = array_slice($simbolos, ($pagina - 1) * $limit, $limit);
    $cotacoes = criptoBuscarCotacoes($paginaSimbolos);

    $ativos = [];
    foreach ($paginaSimbolos as $simbolo) {
        $item = $cotacoes[$simbolo] ?? null;
        $preco = is_array($item) && is_numeric($item['regularMarketPrice'] ?? null)
            ? (float) $item['regularMarketPrice'] : null;
        if ($preco === null || $preco <= 0) continue; // no quote, no trading

        $nome = trim((string) ($item['coinName'] ?? ''));
        $ativos[] = [
            'stock' => $simbolo,
            'name' => $nome !== '' ? $nome : $simbolo,
            'close' => $preco,
            'change' => is_numeric($item['regularMarketChangePercent'] ?? null)
                ? (float) $item['regularMarketChangePercent'] : null,
            'volume' => is_numeric($item['regularMarketVolume'] ?? null)
                ? (int) round((float) $item['regularMarketVolume']) : null,
            'marketCap' => null, // brapi always returns 0 for crypto
            'logo' => criptoLogoSeguro($item['coinImageUrl'] ?? null), // SVG; the app has an SVG loader
        ];
    }

    echo json_encode([
        'success' => true,
        'stocks' => $ativos,
        'paginacao' => [
            'paginaAtual' => $pagina,
            'totalPaginas' => $totalPaginas,
            'totalAtivos' => $total,
            'temProximaPagina' => $pagina < $totalPaginas,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function getCryptoQuote(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    if ($symbol === '' || !criptoSimboloValido($symbol)) {
        responderErroBrapi('Código da criptomoeda ausente ou inválido.', 400);
        return;
    }

    $cotacoes = criptoBuscarCotacoes([$symbol]);
    $item = $cotacoes[$symbol] ?? null;
    if (!is_array($item) || !is_numeric($item['regularMarketPrice'] ?? null)
        || (float) $item['regularMarketPrice'] <= 0) {
        responderErroBrapi('A brapi não retornou cotação para esta criptomoeda.', 404);
        return;
    }

    $nome = trim((string) ($item['coinName'] ?? ''));
    $hora = null;
    if (is_string($item['regularMarketTime'] ?? null) && trim($item['regularMarketTime']) !== '') {
        $hora = $item['regularMarketTime'];
    }
    $data = [
        'symbol' => $symbol,
        'shortName' => $symbol,
        'longName' => $nome !== '' ? $nome : $symbol,
        'regularMarketVolume' => is_numeric($item['regularMarketVolume'] ?? null)
            ? (int) round((float) $item['regularMarketVolume']) : null,
        'regularMarketPrice' => (float) $item['regularMarketPrice'],
        'regularMarketChangePercent' => is_numeric($item['regularMarketChangePercent'] ?? null)
            ? (float) $item['regularMarketChangePercent'] : null,
        'regularMarketTime' => $hora,
        'regularMarketOpen' => null,
        'regularMarketDayHigh' => is_numeric($item['regularMarketDayHigh'] ?? null)
            ? (float) $item['regularMarketDayHigh'] : null,
        'fiftyTwoWeekHigh' => null,
        'fiftyTwoWeekLow' => null,
        'currency' => (string) ($item['currency'] ?? CRIPTO_MOEDA),
    ];

    echo json_encode(
        ['results' => [['requestedSymbol' => $symbol, 'symbol' => $symbol, 'data' => $data]]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

/** Keeps only valid candles (positive close), sorted by date. */
function criptoPontosHistorico(array $moeda): array
{
    $pontos = $moeda['historicalDataPrice'] ?? [];
    if (!is_array($pontos)) return [];

    usort($pontos, static fn($a, $b) => (int) ($a['date'] ?? 0) <=> (int) ($b['date'] ?? 0));
    $saida = [];
    foreach ($pontos as $ponto) {
        if (!is_array($ponto) || !is_numeric($ponto['date'] ?? null)) continue;
        if (!is_numeric($ponto['close'] ?? null) || (float) $ponto['close'] <= 0) continue;
        $num = static fn($v) => is_numeric($v) ? (float) $v : null;
        $saida[] = [
            'date' => (int) $ponto['date'],
            'open' => $num($ponto['open'] ?? null),
            'high' => $num($ponto['high'] ?? null),
            'low' => $num($ponto['low'] ?? null),
            'close' => (float) $ponto['close'],
            'adjustedClose' => $num($ponto['adjustedClose'] ?? null),
            'volume' => is_numeric($ponto['volume'] ?? null) ? (int) round((float) $ponto['volume']) : null,
        ];
    }
    return $saida;
}

function historicoCriptomoedas(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $range = trim((string) ($_GET['range'] ?? '3mo'));
    $periodos = [
        '1d' => '-7 days', '7d' => '-7 days', '1mo' => '-1 month', '3mo' => '-3 months',
        '6mo' => '-6 months', '1y' => '-1 year', '2y' => '-2 years', '5y' => '-5 years',
    ];

    if ($symbol === '' || !criptoSimboloValido($symbol)) {
        responderErroBrapi('Código da criptomoeda ausente ou inválido.', 400);
        return;
    }
    if (!isset($periodos[$range])) {
        responderErroBrapi('Período de histórico inválido.', 400);
        return;
    }

    $buscar = static function (array $extra) use ($symbol): ?array {
        $resposta = brapiRequest('v2/crypto', array_merge(['coin' => $symbol, 'currency' => CRIPTO_MOEDA], $extra));
        if (!$resposta['ok']) {
            return null;
        }
        $json = json_decode((string) $resposta['body'], true);
        $moeda = is_array($json) ? ($json['coins'][0] ?? null) : null;
        return is_array($moeda) ? ['moeda' => $moeda, 'status' => $resposta['status']] : null;
    };

    $pontos = [];
    $usedRange = $range;
    $usedInterval = '1d';

    // "Hoje": hourly candles; if the plan/provider has none, show the last two daily candles.
    if ($range === '1d') {
        $horas = $buscar(['range' => '5d', 'interval' => '1h']);
        $pontosHora = $horas ? criptoPontosHistorico($horas['moeda']) : [];
        if ($pontosHora) {
            $limiteInicio = end($pontosHora)['date'] - 24 * 3600;
            $pontos = array_values(array_filter($pontosHora, static fn($p) => $p['date'] >= $limiteInicio));
            $usedInterval = '1h';
        } else {
            error_log('Historico horario de cripto indisponivel para ' . $symbol . '; usando candles diarios.');
        }
    }

    if (!$pontos) {
        $fuso = new DateTimeZone('America/Sao_Paulo');
        $hoje = new DateTimeImmutable('today', $fuso);
        $inicio = $hoje->modify($periodos[$range]);
        $diario = $buscar(['startDate' => $inicio->format('Y-m-d'), 'endDate' => $hoje->format('Y-m-d')]);
        if ($diario === null) {
            responderErroBrapi('Não foi possível consultar o histórico da criptomoeda na brapi.', 502);
            return;
        }
        $pontos = criptoPontosHistorico($diario['moeda']);
        if ($range === '1d') $pontos = array_slice($pontos, -2);
        $usedInterval = '1d';
    }

    echo json_encode([
        'results' => [[
            'requestedSymbol' => $symbol,
            'symbol' => $symbol,
            'data' => [
                'usedRange' => $usedRange,
                'usedInterval' => $usedInterval,
                'historicalDataPrice' => $pontos,
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
