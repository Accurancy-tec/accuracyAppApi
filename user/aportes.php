<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 
require "../config/cors.php";
require_once "../service/posicaoService.php";
require_once "../service/ativoService.php";

$dados = json_decode(file_get_contents("php://input"), true);

    $simboloAtivo = $dados["ativo_aporte"] ?? null;
    $nomeAtivo = $dados["name_ativo"] ?? null;
    $categoriaAtivo = $dados["categoria_ativo"] ?? null;
    $idWallet = 2;
    $typeContribution = $dados['tipo_aporte'] ?? null;
    $contributionQuantity = $dados['quantidade_aporte'] ?? null;
    $contributionAmount = $dados['valor_aporte'] ?? null;
    $contributionRecurrence = $dados['recorrencia_aporte'];
    $contributionNotes = $dados['observacao_aporte'] ?? null;
    $contributionDate = date("Y-m-d");;

try {
    $conexao->beginTransaction();

    $idAtivo = buscarOuCriarAtivo($conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo); 

    $sql = "INSERT INTO aporte (id_carteira, id_ativo, tipo_aporte,quantidade_aporte,valor_aporte,recorrencia_aporte,observacao_aporte,data_aporte) 
    VALUES (?, ?, ?,?,?,?,?,?)";
        $insert = $conexao->prepare($sql);
        $insert->bindParam(1, $idWallet);
        $insert->bindParam(2, $idAtivo);
        $insert->bindParam(3, $typeContribution);
        $insert->bindParam(4, $contributionQuantity);
        $insert->bindParam(5,$contributionAmount);
        $insert->bindParam(6,$contributionRecurrence);
        $insert->bindParam(7,$contributionNotes);
        $insert->bindParam(8,$contributionDate);
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

} catch (Throwable $erro) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }
?>