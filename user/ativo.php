<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 
require "../config/cors.php";
require_once "../Authentication/Authentication.php";
require_once "../service/AtivoService.php";

autenticar();


$dados = json_decode(file_get_contents("php://input"), true);

    $simboloAtivo = $dados['simbolo_ativo'];
    $nomeAtivo = $dados['nome_ativo'];
    $categoriaAtivo = $dados['categoria_ativo'];

try {

    $idAtivo = buscarOuCriarAtivo($conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo);

        echo json_encode([
            "sucesso" => true,
            "mensagem" => "ativo cadastrado com sucesso"
        ]);

} catch (PDOException $erro) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }
?>