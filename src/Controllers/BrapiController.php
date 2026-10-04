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
