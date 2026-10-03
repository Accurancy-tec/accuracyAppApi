<?php
function login(PDO $conexao, $email)
{
    $sql = "SELECT id_usuario, nome_usuario, email_usuario, senha_usuario FROM usuario WHERE email_usuario = :email_usuario LIMIT 1";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(":email_usuario", $email);
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function criarSessaoUsuario(PDO $conexao, $usuario, $refreshTokenHash, $expiraEm)
{
    $sqlSessao = "INSERT INTO Sessao_Usuario (id_usuario,refresh_token_hash,dispositivo_sessao,expira_em) VALUES (:id_usuario,:refresh_token_hash,:dispositivo_sessao,:expira_em)";

    $stmtSessao = $conexao->prepare($sqlSessao);

    $stmtSessao->bindParam(":id_usuario", $usuario["id_usuario"],);
    $stmtSessao->bindParam(":refresh_token_hash", $refreshTokenHash);
    $stmtSessao->bindValue(":dispositivo_sessao", "Android Accuracy");
    $stmtSessao->bindParam(":expira_em", $expiraEm);

    return $stmtSessao->execute();
}

function getSessaoUsuario(PDO $conexao, $refreshTokenHash)
{
    $sql = "SELECT id_sessao, id_usuario, expira_em, revogado_em FROM Sessao_Usuario 
        WHERE refresh_token_hash = :refresh_token_hash LIMIT 1";

    $stmt = $conexao->prepare($sql);

    $stmt->bindParam(":refresh_token_hash", $refreshTokenHash);

    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function registrarNovoUsuario(PDO $conexao, $nome, $email, $senhaHash, $cpf, $telefone)
{
    $sql = "INSERT INTO usuario (nome_usuario, email_usuario, senha_usuario, cpf_usuario, telefone_usuario, email_verificado_usuario) VALUES (:nome_usuario,:email_usuario, :senha_usuario, :cpf_usuario, :telefone_usuario, FALSE) RETURNING id_usuario";

    $stmt = $conexao->prepare($sql);

    $stmt->bindParam(':nome_usuario', $nome);
    $stmt->bindParam(':email_usuario', $email);
    $stmt->bindParam(':senha_usuario', $senhaHash);
    $stmt->bindParam(':cpf_usuario', $cpf);
    $stmt->bindParam(':telefone_usuario', $telefone);


    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function gerarTokenUsuario(PDO $conexao, $idUsuario, $codigoHash, $expiraEm)
{
    $sqlToken = "INSERT INTO token_usuario
             (id_usuario, tipo_token, token_hash, expira_em)
             VALUES
             (:id_usuario, 'verificacao_email', :token_hash, :expira_em)";

    $stmtToken = $conexao->prepare($sqlToken);

    $stmtToken->bindParam(
        ':id_usuario',
        $idUsuario,
        PDO::PARAM_INT
    );

    $stmtToken->bindParam(
        ':token_hash',
        $codigoHash
    );

    $stmtToken->bindParam(
        ':expira_em',
        $expiraEm
    );

    return $stmtToken->execute();
}

function loginSite(PDO $conexao, $email)
{
    $sql = "SELECT id_usuario, nome_usuario, email_usuario, senha_usuario, email_verificado_usuario FROM usuario WHERE email_usuario = :email_usuario LIMIT 1";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(":email_usuario", $email);
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function findByEmail(PDO $conexao, $email)
{
    $sql = "SELECT id_usuario, email_verificado_usuario FROM usuario WHERE email_usuario = :email LIMIT 1";
    $stmt = $conexao->prepare($sql);
    $stmt->bindValue(
        ":email",
        $email,
        PDO::PARAM_STR
    );

    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function invalidarToken(PDO $conexao, $idUsuario)
{
    $sqlInvalidarTokens = "UPDATE token_usuario SET usado_em = NOW() WHERE id_usuario = :id_usuario AND tipo_token = 'verificacao_email'AND usado_em IS NULL";

    $stmtInvalidar = $conexao->prepare($sqlInvalidarTokens);

    $stmtInvalidar->bindValue(
        ":id_usuario",
        $idUsuario,
        PDO::PARAM_INT
    );

    return $stmtInvalidar->execute();
}

function getUsuarioToken(PDO $conexao, $idUsuario)
{
    $sqlToken = "
        SELECT
            id_token,
            token_hash,
            expira_em
        FROM token_usuario
        WHERE id_usuario = :id_usuario
          AND tipo_token = 'verificacao_email'
          AND usado_em IS NULL
        ORDER BY criado_em DESC
        LIMIT 1
    ";

    $stmtToken = $conexao->prepare($sqlToken);

    $stmtToken->bindValue(
        ":id_usuario",
        $idUsuario,
        PDO::PARAM_INT
    );

    $stmtToken->execute();

    return $stmtToken->fetch(PDO::FETCH_ASSOC);
}

function usarToken(PDO $conexao, $token)
{
    $sqlUsarToken = "
        UPDATE token_usuario
        SET usado_em = NOW()
        WHERE id_token = :id_token
    ";

    $stmtUsarToken = $conexao->prepare($sqlUsarToken);

    $stmtUsarToken->bindValue(
        ":id_token",
        $token["id_token"],
        PDO::PARAM_INT
    );

    return $stmtUsarToken->execute();
}

function verificarEmail(PDO $conexao, $idUsuario){
    $sqlVerificarEmail = "
        UPDATE usuario
        SET
            email_verificado_usuario = TRUE,
            atualizado_em = NOW()
        WHERE id_usuario = :id_usuario
    ";

        $stmtVerificarEmail = $conexao->prepare(
            $sqlVerificarEmail
        );

        $stmtVerificarEmail->bindValue(
            ":id_usuario",
            $idUsuario,
            PDO::PARAM_INT
        );

        $stmtVerificarEmail->execute();
}
