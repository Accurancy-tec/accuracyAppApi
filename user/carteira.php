<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 
include "../Authentication/Authentication.php";
require "../config/cors.php";
require_once "../Authentication/Authentication.php";
require_once "../service/CarteiraService.php";

$dados = json_decode(file_get_contents("php://input"), true);

    $idUser = autenticar();
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
?>