<?php

require_once __DIR__ . "/../Repositories/PosicaoRepository.php";
require_once __DIR__ . "/../Repositories/CarteiraRepository.php";

function criarCarteiraService(PDO $conexao, $idUsuario, $nomeCarteira, $tipoCarteira)
{
    return registrarCarteira($conexao, $idUsuario, $nomeCarteira, $tipoCarteira); 
}

function carteiraPertenceAoUsuarioService(PDO $conexao, $idCarteira, $idUsuario){
    return carteiraPertenceAoUsuario($conexao, $idCarteira, $idUsuario);
}

function getCarteirasDoUsuarioService(PDO $conexao, $id_usuario){
    return getCarteirasDoUsuario($conexao, $id_usuario);
}
function buscarPosicoesService(PDO $conexao, $idCarteira)
{
    return buscarPosicoes($conexao, $idCarteira);
}
