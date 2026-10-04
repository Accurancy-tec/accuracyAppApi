<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/cors.php";
require_once __DIR__ . "/../database/conexao.php";
require_once __DIR__ . "/../Authentication/Authentication.php";
require_once __DIR__ . "/../service/posicaoService.php";
require_once __DIR__ . "/../service/aporteRecorrenteService.php";   // carteiraPertenceAoUsuario()

$idUsuario = autenticar();

$idCarteira = $_GET['id_carteira'] ?? null;

if (!$idCarteira) {
    http_response_code(400);
    echo json_encode(["sucesso" => false, "mensagem" => "ID da carteira não fornecido."]);
    exit;
}

try {

    // antes qualquer usuário logado lia a carteira de qualquer outro só trocando o id na URL
    if (!carteiraPertenceAoUsuario($conexao, $idCarteira, $idUsuario)) {
        http_response_code(403);
        echo json_encode(["sucesso" => false, "mensagem" => "A carteira não pertence ao usuário."]);
        exit;
    }

    $posicoes = buscarPosicoes($conexao, $idCarteira);

    echo json_encode(["sucesso" => true, "posicoes" => $posicoes]);

} catch (Throwable $erro) {

    error_log("itemInvestimento.php: " . $erro->getMessage());
    http_response_code(500);
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao buscar as posições."]);
}
?>