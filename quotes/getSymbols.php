<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../service/BrapiService.php';

try{
    $reponse = buscarSymbols();
    echo $reponse;
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
    exit();
}

?>