<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 
include "../Authentication/Authentication.php";

$dados = json_decode(file_get_contents("php://input"), true);

    $idUser = autenticar();
    $nameWallet = $dados['nome_carteira'];
    $typeWallet = $dados['tipo_carteira'];
    $availableWalletBalance = $dados['saldo_livre_carteira'];
    

try {

    $sql = "INSERT INTO carteira (id_usuario, nome_carteira, tipo_carteira,saldo_livre_carteira) VALUES (?, ?, ?,?)";
        $insert = $conexao->prepare($sql);
        $insert->bindParam(1, $idUser);
        $insert->bindParam(2, $nameWallet);
        $insert->bindParam(3, $typeWallet);
        $insert->bindParam(4, $availableWalletBalance);
        $insert->execute();

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