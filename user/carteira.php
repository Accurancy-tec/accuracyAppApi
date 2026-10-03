<?php
require_once __DIR__ . "/../config/cors.php";
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php";;
require_once "../Authentication/Authentication.php";
require_once "../service/carteiraService.php";

$idUser = autenticar();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    try {

        $carteiras = listarCarteiras($conexao, $idUser);

        echo json_encode([
            "sucesso" => true,
            "carteiras" => $carteiras
        ]);
    } catch (PDOException $erro) {

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao buscar carteiras: " . $erro->getMessage()
        ]);
    }

    exit;
}

$dados = json_decode(file_get_contents("php://input"), true);

$nameWallet = $dados['nome_carteira'];
$typeWallet = $dados['tipo_carteira'];
$availableWalletBalance = $dados['saldo_livre_carteira'];

try {

    $idCarteira = criarCarteira($conexao, $idUser, $nameWallet, $typeWallet, $availableWalletBalance);

    echo json_encode([
        "sucesso" => true,
        "mensagem" => "carteira cadastrado com sucesso"
    ]);
} catch (PDOException $erro) {
    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Erro ao salvar: " . $erro->getMessage()
    ]);
}
