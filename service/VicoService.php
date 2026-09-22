<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../Authentication/Authentication.php";
require "../database/conexao.php";

$id = autenticar();
   $sql = $conexao->prepare("SELECT * from aporte where id_carteira = ? order by id_aporte limit 4");
    $sql->bindParam(1, $id);
    $sql->execute();

    $resposta = $sql->fetchAll(PDO::FETCH_ASSOC);

$dados = [];

foreach($resposta as $row){
    $dados[] = [
        "id_ativo" => $row["id_ativo"],
        "valor_aporte" => (float)$row["valor_aporte"]
    ];
}

echo json_encode(["dados" => $dados]);
?>