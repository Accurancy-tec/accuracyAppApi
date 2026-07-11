<?php
require "../database/conexao.php";
header("Content-Type: application/json; charset=UTF-8");
$dados = json_decode(file_get_contents("php://input"), true);


   $sql = $conexao->prepare("SELECT * from tb_aporte");
    $sql->execute();

    $resposta = $sql->fetchAll(PDO::FETCH_ASSOC);

$dados = [];

foreach($resposta as $row){
    $dados[] = [
        "ativo_aporte" => $row["ativo_aporte"],
        "preco_aporte" => (float)$row["preco_aporte"]
    ];
}

echo json_encode(["dados" => $dados]);
?>