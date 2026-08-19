<?php
require "../database/conexao.php";
header("Content-Type: application/json; charset=UTF-8");

include "Authentication.php";

$id = autenticar();

$dados = json_decode(file_get_contents("php://input"), true);


    $sql = $conexao->prepare("SELECT * from tb_aporte where id_usuario = ? order by id_aporte limit 4");
    $sql->bindParam(1,$id);

    $sql->execute();

    $resposta = $sql->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["sucesso" => true, "ativos" => $resposta]);


?>