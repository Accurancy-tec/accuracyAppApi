<?php 
    function loginService(PDO $conexao, $email){
        return login($conexao, $email);
    }

    function criarSessaoUsuarioService(PDO $conexao, $usuario, $refreshTokenHash, $expiraEm){
        return criarSessaoUsuario($conexao, $usuario, $refreshTokenHash, $expiraEm);
    }

    function getSessaoUsuarioService(PDO $conexao, $refreshTokenHash){
        return getSessaoUsuario($conexao, $refreshTokenHash);
    }

    function registrarNovoUsuarioService(PDO $conexao, $nome, $email, $senhaHash, $cpf, $telefone){
        return registrarNovoUsuario($conexao, $nome, $email, $senhaHash, $cpf, $telefone);
    }

    function gerarTokenUsuarioService(PDO $conexao, $idUsuario, $codigoHash, $expiraEm){
        return gerarTokenUsuario($conexao, $idUsuario, $codigoHash, $expiraEm);
    }
?>