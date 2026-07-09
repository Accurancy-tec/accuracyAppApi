<?php 

require_once 'config/config.php';


function buscarAcao($ticker){
    $url = BRAPI_BASE_URL . "/quote/$ticker";

    $curl = curl_init($url);

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($curl, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . BRAPI_TOKEN
   
    ]);

    $response = curl_exec($curl);

    return $response;

}

function buscarSymbols(){
    $url = BRAPI_BASE_URL . "/v2/tickers";

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . BRAPI_TOKEN
    ]);

    $response = curl_exec($curl);
    return $response;
}
?>