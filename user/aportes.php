<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 
require "../config/cors.php";
require_once "../service/PosicaoService.php";
require_once "../service/ativoService.php";

$dados = json_decode(file_get_contents("php://input"), true);

    $idWallet = $dados['id_carteira'];
    $typeContribution = $dados['tipo_aporte'];
    $contributionQuantity = $dados['quantidade_aporte'];
    $contributionAmount = $dados['valor_aporte'];
    $contributionRecurrence = $dados['recorrencia_aporte'];
    $contributionStatus = $dados['status_aporte'];
    $contributionNotes = $dados['observacao_aporte'];
    $contributionDate = $dados['data_aporte'];

try {
    $idAtivo = buscarOuCriarAtivo($conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo);

    $sql = "INSERT INTO aporte (id_carteira, id_ativo, tipo_aporte,quantidade_aporte,valor_aporte,recorrencia_aporte,status_aporte,observacao_aporte,data_aporte) 
    VALUES (?, ?, ?, ?,?,?,?,?,?)";
        $insert = $conexao->prepare($sql);
        $insert->bindParam(1, $idWallet);
        $insert->bindParam(2, $idAtivo);
        $insert->bindParam(3, $typeContribution);
        $insert->bindParam(4, $contributionQuantity);
        $insert->bindParam(5,$contributionAmount);
        $insert->bindParam(6,$contributionRecurrence);
        $insert->bindParam(7,$contributionStatus);
        $insert->bindParam(8,$contributionNotes);
        $insert->bindParam(9,$contributionDate);
        $insert->execute();


        $posicaoAtualizada = atualizarPosicao($conexao, $idWallet, $idAtivo, $typeContribution, $contributionQuantity, $contributionAmount);

        if(!$posicaoAtualizada) {
            throw new Exception("Quantidade Insuficiente para venda. A operação não pode ser concluída.");
          
         
        }
        $conexao->commit();

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