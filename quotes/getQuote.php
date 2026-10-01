<?php 

require_once '../accuracyApi/src/Services/BrapiService.php';

$ticker = $_GET['symbol'];


if(empty($ticker)){
    echo json_encode([
        "success" => false,
        "message" => "Ticker não informado"
    ]);
    exit();
}

$reponse = buscarAcao($ticker);

echo $reponse;
?>