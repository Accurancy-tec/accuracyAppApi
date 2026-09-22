<?php

header("Content-Type: application/json; charset=UTF-8");

require_once "../database/conexao.php";

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
    $codigo = trim($dados["codigo"] ?? "");

    if ($email === "" || $codigo === "") {
        http_response_code(400);

        echo json_encode([
            "status" => "erro",
            "mensagem" => "E-mail e código são obrigatórios."
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

    if (!preg_match("/^[0-9]{6}$/", $codigo)) {
        http_response_code(400);

        echo json_encode([
            "status" => "erro",
            "mensagem" => "O código deve possuir 6 números."
        ]);

        exit;
    }

    $sql = "
        SELECT
            id_usuario,
            email_verificado_usuario
        FROM usuario
        WHERE email_usuario = :email
        LIMIT 1
    ";

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

    $idUsuario = $usuario["id_usuario"];

    if (
        $usuario["email_verificado_usuario"] === true ||
        $usuario["email_verificado_usuario"] === "t"
    ) {
        echo json_encode([
            "status" => "sucesso",
            "mensagem" => "E-mail já verificado."
        ]);

        exit;
    }

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

    $token = $stmtToken->fetch(PDO::FETCH_ASSOC);

    if (!$token) {
        http_response_code(404);

        echo json_encode([
            "status" => "erro",
            "mensagem" => "Nenhum código de verificação válido foi encontrado."
        ]);

        exit;
    }

    if (strtotime($token["expira_em"]) < time()) {
        echo json_encode([
            "status" => "erro",
            "mensagem" => "O código de verificação expirou."
        ]);

        exit;
    }

    if (!password_verify($codigo, $token["token_hash"])) {
        echo json_encode([
            "status" => "erro",
            "mensagem" => "Código de verificação inválido."
        ]);

        exit;
    }

    $conexao->beginTransaction();

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

    $stmtUsarToken->execute();

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

    $conexao->commit();

    echo json_encode([
        "status" => "sucesso",
        "mensagem" => "E-mail verificado com sucesso!"
    ]);

} catch (PDOException $erro) {

    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        "status" => "erro",
        "mensagem" => "Erro ao processar a verificação."
    ]);

} catch (Exception $erro) {

    if ($conexao->inTransaction()) {
        $conexao->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        "status" => "erro",
        "mensagem" => "Erro interno da API."
    ]);
}
?>