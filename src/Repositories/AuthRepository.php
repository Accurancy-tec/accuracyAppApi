<?php
function loginUsuario(PDO $conexao, $email)
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

function loginUsuarioSite(PDO $conexao, $email)
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

function verificarEmail(PDO $conexao, $idUsuario)
{
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
function perfilBuscarUsuario(PDO $c, $id)
{
    $st = $c->prepare("SELECT id_usuario, nome_usuario, email_usuario, foto_usuario
                       FROM usuario WHERE id_usuario = :id LIMIT 1");
    $st->bindValue(":id", $id, PDO::PARAM_INT);
    $st->execute();
    return $st->fetch(PDO::FETCH_ASSOC);
}

function perfilSalvarFoto(PDO $c, $id, $url)
{
    $st = $c->prepare("UPDATE usuario SET foto_usuario = :url, atualizado_em = NOW()
                       WHERE id_usuario = :id");
    $st->bindValue(":url", $url);
    $st->bindValue(":id", $id, PDO::PARAM_INT);
    return $st->execute();
}

function perfilEmailEmUso(PDO $c, $email, $idIgnorar)
{
    $st = $c->prepare("SELECT 1 FROM usuario
                       WHERE LOWER(email_usuario) = LOWER(:email) AND id_usuario <> :id LIMIT 1");
    $st->bindValue(":email", $email);
    $st->bindValue(":id", $idIgnorar, PDO::PARAM_INT);
    $st->execute();
    return (bool) $st->fetchColumn();
}

function perfilCriarToken(PDO $c, $id, $tipo, $hash, $novoEmail = null)
{
    // invalida códigos anteriores do mesmo tipo
    $st = $c->prepare("UPDATE token_alteracao_usuario SET usado_em = NOW()
                       WHERE id_usuario = :id AND tipo_token = :tipo AND usado_em IS NULL");
    $st->bindValue(":id", $id, PDO::PARAM_INT);
    $st->bindValue(":tipo", $tipo);
    $st->execute();

    $st = $c->prepare("INSERT INTO token_alteracao_usuario
                         (id_usuario, tipo_token, novo_email, token_hash, expira_em)
                       VALUES (:id, :tipo, :novo_email, :hash, NOW() + INTERVAL '10 minutes')");
    $st->bindValue(":id", $id, PDO::PARAM_INT);
    $st->bindValue(":tipo", $tipo);
    $st->bindValue(":novo_email", $novoEmail);
    $st->bindValue(":hash", $hash);
    return $st->execute();
}

function perfilBuscarToken(PDO $c, $id, $tipo)
{
    $st = $c->prepare("SELECT id_token, token_hash, novo_email, tentativas,
                              (expira_em > NOW()) AS valido,
                              EXTRACT(EPOCH FROM (NOW() - criado_em)) AS segundos
                       FROM token_alteracao_usuario
                       WHERE id_usuario = :id AND tipo_token = :tipo AND usado_em IS NULL
                       ORDER BY criado_em DESC LIMIT 1");
    $st->bindValue(":id", $id, PDO::PARAM_INT);
    $st->bindValue(":tipo", $tipo);
    $st->execute();
    return $st->fetch(PDO::FETCH_ASSOC);
}

function perfilAnotarTentativa(PDO $c, $idToken)
{
    $st = $c->prepare("UPDATE token_alteracao_usuario SET tentativas = tentativas + 1
                       WHERE id_token = :id");
    $st->bindValue(":id", $idToken, PDO::PARAM_INT);
    return $st->execute();
}

function perfilUsarToken(PDO $c, $idToken)
{
    $st = $c->prepare("UPDATE token_alteracao_usuario SET usado_em = NOW() WHERE id_token = :id");
    $st->bindValue(":id", $idToken, PDO::PARAM_INT);
    return $st->execute();
}

function perfilAtualizarSenha(PDO $c, $id, $hash)
{
    $st = $c->prepare("UPDATE usuario SET senha_usuario = :hash, atualizado_em = NOW()
                       WHERE id_usuario = :id");
    $st->bindValue(":hash", $hash);
    $st->bindValue(":id", $id, PDO::PARAM_INT);
    return $st->execute();
}

function perfilAtualizarEmail(PDO $c, $id, $email)
{
    $st = $c->prepare("UPDATE usuario
                       SET email_usuario = :email, email_verificado_usuario = TRUE, atualizado_em = NOW()
                       WHERE id_usuario = :id");
    $st->bindValue(":email", $email);
    $st->bindValue(":id", $id, PDO::PARAM_INT);
    return $st->execute();
}

// Depois de trocar a senha, derruba as sessões (refresh tokens) abertas do app
function perfilRevogarSessoes(PDO $c, $id)
{
    $st = $c->prepare("UPDATE Sessao_Usuario SET revogado_em = NOW()
                       WHERE id_usuario = :id AND revogado_em IS NULL");
    $st->bindValue(":id", $id, PDO::PARAM_INT);
    return $st->execute();
}
