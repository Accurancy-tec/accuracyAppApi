<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 

$dados = json_decode(file_get_contents("php://input"), true);

    $idWallet = $dados['id_carteira'];
    $idActive = $dados['id_ativo'];
    $itemQuantity = $dados['quantidade_item'];
    $averageItemPrice = $dados['preco_medio_item'];
    

try {

    $sql = "INSERT INTO item_investimento (idWallet, idActive, itemQuantity,averageItemPrice) VALUES (?, ?, ?,?)";
        $insert = $conexao->prepare($sql);
        $insert->bindParam(1, $idWallet);
        $insert->bindParam(2, $idActive);
        $insert->bindParam(3, $itemQuantity);
        $insert->bindParam(4, $averageItemPrice);
        $insert->execute();

        echo json_encode([
            "sucesso" => true,
            "mensagem" => "Item cadastrado com sucesso"
        ]);

} catch (PDOException $erro) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }
?>