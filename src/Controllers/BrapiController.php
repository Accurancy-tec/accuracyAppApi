<?php
require_once __DIR__ . "/../../config/cors.php";

function buscarAcao(String $ticker)
{
    $url = getenv("BRAPI_BASE_URL") . "/v2/stocks/quote?symbols=" . urlencode($ticker);

    $curl = curl_init($url);

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . getenv("BRAPI_TOKEN"),
            "Accept: application/json"
        ]
    ]);

    $inicio = microtime(true);
    $response = curl_exec($curl);

    $fim = microtime(true);

    error_log("Tempo CURL: " . ($fim - $inicio));

    if ($response == false) {
        die(curl_error($curl));
    }

    return $response;
}

function buscarSymbols()
{

    $url = getenv("BRAPI_BASE_URL") . "/v2/tickers";

    $curl = curl_init($url);

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . getenv("BRAPI_TOKEN"),
            "Accept: application/json"
        ]
    ]);

    $inicio = microtime(true);
    $response = curl_exec($curl);
    if ($response === false) {
        die("Erro CURL: " . curl_errno($curl) . " - " . curl_error($curl));
    }
    $fim = microtime(true);

    error_log("Tempo CURL: " . ($fim - $inicio));

    if ($response == false) {
        die(curl_error($curl));
    }

    return $response;
}

function getQuote()
{
    $ticker = $_GET['symbol'];


    if (empty($ticker)) {
        echo json_encode([
            "success" => false,
            "message" => "Ticker não informado"
        ]);
        exit();
    }

    $reponse = buscarAcao($ticker);

    echo $reponse;
}

function getSymbols()
{
    header("Content-Type: application/json; charset=UTF-8");

    try {
        $reponse = buscarSymbols();
        echo $reponse;
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);
        exit();
    }
}


// Lista as açoes nacionais da brapi com paginação, o limite de ações por requisição é 20

function listarAcoesNacionais(){
    header('Content-Type: application/json; charset=utf-8');

    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);

    $page = $page === false || $page === null ? 1 : $page;
    $limit = $limit === false || $limit === null ? 20 : $limit;

    if($page < 1 || $limit < 1 || $limit > 20){
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => "Página inválida ou limite fora do intervalo"
        ]);
        return;
    }

    $token = $_ENV[BRAPI_TOKEN];

    if($token == ''){
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => "Token não configurado" 
        ]);
        return;
    }

    $url = getenv("BRAPI_BASE_URL") . '/quote/list?' . http_build_query([
        'page' => $page,
        'limit' => $limit,
        'type' => 'stock'
    ]);

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);

    $resposta = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($resposta === false || $status < 200 || $status >= 300) {
        http_response_code(502);

        echo json_encode([
            'success' => false,
            'message' => 'Erro ao consultar as ações na brapi.'
        ]);

        return;
    }

    $dados = json_decode($resposta, true);

    if(!is_array($dados) || !isset($dados['sotcks'])){
        http_response_code(502);

        echo json_encode([
            'success' => false,
            'message' => "Resposta inválida da brapi"
        ]);

        return;
    }

    echo json_encode([
        'success' => true,
        'acoes' => $dados['stocks'],
        'paginacao' => [
            'paginaAtual' => $dados['currentPage'] ?? $page,
            'totalPaginas' => $dados['totalPages'] ?? null,
            'totalAtivos' => $dados['totalCount'] ?? null,
            'temProximaPagina' => $dados['hasNextPage'] ?? false
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}