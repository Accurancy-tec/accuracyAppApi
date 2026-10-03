<?php

require_once __DIR__ . "/../Repositories/CarteiraRepository.php";
// cria a carteira do usuario

// e retorna o id dela
function criarCarteiraService(PDO $conexao, $idUsuario, $nomeCarteira, $tipoCarteira, $saldoLivre)
{
    return registrarCarteira($conexao, $idUsuario, $nomeCarteira, $tipoCarteira, $saldoLivre); 
}

function carteiraPertenceAoUsuarioService(PDO $conexao, $idCarteira, $idUsuario){
    return carteiraPertenceAoUsuario($conexao, $idCarteira, $idUsuario);
}

function getCarteirasDoUsuarioService(PDO $conexao, $id_usuario){
    return getCarteirasDoUsuario($conexao, $id_usuario);
}