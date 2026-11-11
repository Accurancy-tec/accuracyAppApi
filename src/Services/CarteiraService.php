<?php

require_once __DIR__ . "/../Repositories/CarteiraRepository.php";

function criarCarteiraService(PDO $conexao, $idUsuario, $nomeCarteira, $estilo_carteira, $meta_carteira, $tipoCarteira)
{
    return registrarCarteira($conexao, $idUsuario, $nomeCarteira, $tipoCarteira, $estilo_carteira, $meta_carteira); 
}

function carteiraPertenceAoUsuarioService(PDO $conexao, $idCarteira, $idUsuario){
    return carteiraPertenceAoUsuario($conexao, $idCarteira, $idUsuario);
}

function getCarteirasDoUsuarioService(PDO $conexao, $id_usuario){
    return getCarteirasDoUsuario($conexao, $id_usuario);
}

function deletarCarteiraService(PDO $conexao, $id_usuario, $id_carteira){
    return deletarCarteira($conexao, $id_usuario, $id_carteira);
}