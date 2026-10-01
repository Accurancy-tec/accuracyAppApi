<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 
require "../config/cors.php";
require_once "../accuracyApi/src/Repositories/PosicaoRepository.php";
require_once "../accuracyApi/src/Repositories/AtivoRepository.php";
require_once "../accuracyApi/src/Services/AporteService.php";

function buscarAportesDoUsuario(){
    global $conexao;

    try{
        $id_usuario = autenticar();

        $aportes = listarAportesUsuarioService($conexao, $id_usuario);

        echo json_encode([
            "sucesso" => true,
            "ativos" => $aportes 
        ]);
    }
    catch(PDOException $e){
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao buscar aportes" . $e->getMessage()
        ]);
    }

}

function fazerAporte(){
    global $conexao;
    
    $dados = json_decode(file_get_contents("php://input"), true);

    $simboloAtivo = $dados["ativo_aporte"] ?? null;
    $nomeAtivo = $dados["name_ativo"] ?? null;
    $categoriaAtivo = $dados["categoria_ativo"] ?? null;
    $idWallet = 28;
    $typeContribution = $dados['tipo_aporte'] ?? null;
    $contributionQuantity = $dados['quantidade_aporte'] ?? null;
    $contributionAmount = $dados['valor_aporte'] ?? null;
    $contributionRecurrence = $dados['recorrencia_aporte'];
    $contributionNotes = $dados['observacao_aporte'] ?? null;
    $contributionDate = date("Y-m-d");

try {
    $conexao->beginTransaction();

    $idAtivo = buscarOuCriarAtivo($conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo); 

    $aporte = cadastrarAporteService($conexao, $idWallet, $idAtivo, $typeContribution, $contributionQuantity, $contributionAmount, $contributionRecurrence, $contributionNotes, $contributionDate);

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
}
