<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 
require "../config/cors.php";
require_once "../Authentication/Authentication.php";
require_once "../service/PosicaoService.php";

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