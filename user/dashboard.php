<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php";
require_once __DIR__ . "/../Authentication/Authentication.php";

$id = autenticar();

    $sql = $conexao->prepare("SELECT * from aporte where id_carteira = ? order by id_aporte limit 4");
    $sql->bindParam(1,$id);

    $sql->execute();

    $resposta = $sql->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["sucesso" => true, "ativos" => $resposta]);


?>