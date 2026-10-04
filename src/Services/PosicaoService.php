<?php

require_once __DIR__ . "/../Repositories/PosicaoRepository.php";

// Atualiza item_investimento da carteira, depois do aporte
 
function atualizarPosicaoService(PDO $conexao, $idCarteira, $idAtivo, $tipoAporte, $quantidade, $valorTotal)
{
    return atualizarPosicao($conexao, $idCarteira, $idAtivo, $tipoAporte, $quantidade, $valorTotal);
}


// traz símbolo e nome do ativo.

function buscarPosicoesService(PDO $conexao, $idCarteira)
{
    return buscarPosicoes($conexao, $idCarteira);
}