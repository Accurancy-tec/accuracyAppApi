<?php
require_once __DIR__ . "/../Repositories/AuthRepository.php";

    function loginService(PDO $conexao, $email){
        return loginUsuario($conexao, $email);
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

    function loginUsuarioSiteService(PDO $conexao, $email){
        return loginSite($conexao, $email);
    }

    function findByEmailService(PDO $conexao, $email){
        return findByEmail($conexao, $email);
    }

    function invalidarTokenService(PDO $conexao, $idUsuario){
        return invalidarToken($conexao, $idUsuario);
    }

    function getUsuarioTokenService(PDO $conexao, $idUsuario){
        return getUsuarioToken($conexao, $idUsuario);
    }
    
    function usarTokenService(PDO $conexao, $token){
        return usarToken($conexao, $token);
    }

    function verificarEmailService(PDO $conexao, $idUsuario){
        return verificarEmail($conexao, $idUsuario);
    }
?>