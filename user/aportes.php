<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 
include "Authentication.php";


$dados = json_decode(file_get_contents("php://input"), true);

    $ativo = $dados['ativo_aporte'];
    $preco = $dados['preco_aporte'];
    $tipo = $dados['tipo_aporte'];
    $recorrencia = $dados['recorrencia_aporte'];
    $id = autenticar();

try {

    $sql = "INSERT INTO tb_aporte (ativo_aporte, preco_aporte, tipo_aporte, recorrencia_aporte,id_usuario) VALUES (?, ?, ?, ?,?)";
        $insert = $conexao->prepare($sql);
        $insert->bindParam(1, $ativo);
        $insert->bindParam(2, $preco);
        $insert->bindParam(3, $tipo);
        $insert->bindParam(4, $recorrencia);
        $insert->bindParam(5,$id);
        $insert->execute();

        echo json_encode([
            "sucesso" => true,
            "mensagem" => "Aporte cadastrado com sucesso"
        ]);

} catch (PDOException $erro) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }
?>