<?php

header("Content-Type: application/json; charset=UTF-8");

require_once "../database/conexao.php";
require_once "../config/email.php";

try {

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        http_response_code(405);

        echo json_encode([
            "status" => "erro",
            "mensagem" => "Método não permitido."
        ]);

        exit;
    }

    $dados = json_decode(
        file_get_contents("php://input"),
        true
    );

    $email = trim($dados["email_usuario"] ?? "");

    if ($email === "") {
        http_response_code(400);

        echo json_encode([
            "status" => "erro",
            "mensagem" => "E-mail é obrigatório."
        ]);

        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);

        echo json_encode([
            "status" => "erro",
            "mensagem" => "E-mail inválido."
        ]);

        exit;
    }

    $sql = "SELECT id_usuario, email_verificado_usuario FROM usuario WHERE email_usuario = :email LIMIT 1";

    $stmt = $conexao->prepare($sql);

    $stmt->bindValue(
        ":email",
        $email,
        PDO::PARAM_STR
    );

    $stmt->execute();

    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        http_response_code(404);

        echo json_encode([
            "status" => "erro",
            "mensagem" => "Usuário não encontrado."
        ]);

        exit;
    }

    if (
        $usuario["email_verificado_usuario"] === true ||
        $usuario["email_verificado_usuario"] === "t"
    ) {
        echo json_encode([
            "status" => "erro",
            "mensagem" => "Este e-mail já foi verificado."
        ]);

        exit;
    }

    $idUsuario = $usuario["id_usuario"];

    $codigo = str_pad(
        random_int(0, 999999),
        6,
        "0",
        STR_PAD_LEFT
    );

    $codigoHash = password_hash(
        $codigo,
        PASSWORD_DEFAULT
    );

    $expiraEm = date(
        "Y-m-d H:i:s",
        time() + (10 * 60)
    );

    $conexao->beginTransaction();

    $sqlInvalidarTokens = "UPDATE token_usuario SET usado_em = NOW() WHERE id_usuario = :id_usuario AND tipo_token = 'verificacao_email'AND usado_em IS NULL";

    $stmtInvalidar = $conexao->prepare(
        $sqlInvalidarTokens
    );

    $stmtInvalidar->bindValue(
        ":id_usuario",
        $idUsuario,
        PDO::PARAM_INT
    );

    $stmtInvalidar->execute();

    $sqlToken = "INSERT INTO token_usuario (id_usuario,tipo_token, token_hash, expira_em )VALUES
( :id_usuario,'verificacao_email',:token_hash,:expira_em)";

    $stmtToken = $conexao->prepare($sqlToken);

    $stmtToken->bindValue(
        ":id_usuario",
        $idUsuario,
        PDO::PARAM_INT
    );

    $stmtToken->bindValue(
        ":token_hash",
        $codigoHash,
        PDO::PARAM_STR
    );

    $stmtToken->bindValue(
        ":expira_em",
        $expiraEm,
        PDO::PARAM_STR
    );

    $stmtToken->execute();

    $conexao->commit();

    $enviado = enviarCodigo(
        $email,
        $codigo
    );

    if (!$enviado) {
        http_response_code(500);

        echo json_encode([
            "status" => "erro",
            "mensagem" => "Não foi possível enviar o código de verificação."
        ]);

        exit;
    }

    echo json_encode([
        "status" => "sucesso",
        "mensagem" => "Novo código de verificação enviado."
    ]);
}  catch (PDOException $erro) {

    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        "status" => "erro",
        "mensagem" => $erro->getMessage()
    ]);

} catch (Exception $erro) {

    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        "status" => "erro",
        "mensagem" => $erro->getMessage()
    ]);
}