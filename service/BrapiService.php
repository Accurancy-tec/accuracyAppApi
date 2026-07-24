<?php 

require_once '../config/config.php';


function buscarAcao($ticker){
    $url = BRAPI_BASE_URL . "/v2/stocks/quote?symbols=" . urlencode($ticker);

    $curl = curl_init($url);

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . BRAPI_TOKEN,
            "Accept: application/json"
        ]
    ]);

    $inicio = microtime(true);
    $response = curl_exec($curl);

    $fim = microtime(true);

    error_log("Tempo CURL: " . ($fim - $inicio));

    if($response == false){
        die(curl_error($curl));
    }

    return $response;

}

function buscarSymbols(){

     $url = BRAPI_BASE_URL . "/v2/tickers";

    $curl = curl_init($url);

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . BRAPI_TOKEN,
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

    if($response == false){
        die(curl_error($curl));
    }

    return $response;
}
?>