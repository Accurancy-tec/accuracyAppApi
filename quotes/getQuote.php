<?php 
require_once __DIR__ . "/../config/cors.php";
require_once '../service/BrapiService.php';

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