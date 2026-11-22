<?php

require_once __DIR__ . "/../Repositories/CarteiraRepository.php";

function criarCarteiraService(PDO $conexao, $idUsuario, $nomeCarteira, $estiloCarteira, $metaCarteira, $tipoCarteira = 'Real')
{
    return registrarCarteira(
        $conexao,
        $idUsuario,
        $nomeCarteira,
        $tipoCarteira,
        $estiloCarteira,
        $metaCarteira
    );
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

function adicionarSaldoService(PDO $conexao, $id_usuario, $valor){
    return adicionarSaldoRepository($conexao, $id_usuario, $valor);
}