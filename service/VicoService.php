<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../Authentication/Authentication.php";
require "../database/conexao.php";

$id = autenticar();
   $sql = $conexao->prepare("SELECT * from tb_aporte where id_usuario = ? order by id_aporte limit 4");
    $sql->bindParam(1, $id);
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