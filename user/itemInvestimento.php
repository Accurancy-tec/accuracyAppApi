<?php
require_once __DIR__ . "/../config/cors.php";
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 
require_once "../Authentication/Authentication.php";
require_once "../service/posicaoService.php";

autenticar();

$idCarteira = $_GET['id_carteira'] ?? null;

if (!$idCarteira) {
    echo json_encode([
        "sucesso" => false,
        "mensagem" => "ID da carteira não fornecido."
    ]);
    exit;
}

try {

    $posicoes = buscarPosicoes($conexao, $idCarteira);

        echo json_encode([
            "sucesso" => true,
            "posicoes" => $posicoes
        ]);

} catch (PDOException $erro) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }
?>