<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 


$dados = json_decode(file_get_contents("php://input"), true);

    $idWallet = $dados['id_carteira'];
    $idActive = $dados['id_ativo'];
    $typeContribution = $dados['tipo_aporte'];
    $contributionQuantity = $dados['quantidade_aporte'];
    $contributionAmount = $dados['valor_aporte'];
    $contributionRecurrence = $dados['recorrencia_aporte'];
    $contributionStatus = $dados['status_aporte'];
    $contributionNotes = $dados['observacao_aporte'];
    $contributionDate = $dados['data_aporte'];

try {

    $sql = "INSERT INTO aporte (id_carteira, id_ativo, tipo_aporte,quantidade_aporte,valor_aporte,recorrencia_aporte,status_aporte,observacao_aporte,data_aporte) VALUES (?, ?, ?, ?,?,?,?,?,?)";
        $insert = $conexao->prepare($sql);
        $insert->bindParam(1, $idWallet);
        $insert->bindParam(2, $idActive);
        $insert->bindParam(3, $typeContribution);
        $insert->bindParam(4, $contributionQuantity);
        $insert->bindParam(5,$contributionAmount);
        $insert->bindParam(6,$contributionRecurrence);
        $insert->bindParam(7,$contributionStatus);
        $insert->bindParam(8,$contributionNotes);
        $insert->bindParam(9,$contributionDate);
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