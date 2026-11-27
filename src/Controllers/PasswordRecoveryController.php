<?php

require_once __DIR__ . "/AuthController.php";

const PASSWORD_RECOVERY_TOKEN_TYPE = "recuperar_senha";

/**
 * POST auth/recuperarSenha {email_usuario}: e-mails a 6-digit code. The answer is the same whether
 * the account exists or not, so it does not reveal which e-mails are registered.
 */
function forgotPasswordController(): void
{
    global $conexao;
    $email = mb_strtolower(trim((string) (perfilEntrada()["email_usuario"] ?? "")), "UTF-8");

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        perfilResponder(400, ["success" => false, "message" => "Informe um e-mail válido."]);
    }

    $answer = ["success" => true, "message" => "Se houver uma conta com esse e-mail, enviamos um código de 6 números para ele."];
    $user = findByEmailService($conexao, $email);
    if (!$user) {
        perfilResponder(200, $answer);
    }

    $userId = (int) $user["id_usuario"];
    $previous = perfilBuscarToken($conexao, $userId, PASSWORD_RECOVERY_TOKEN_TYPE);
    if ($previous && (float) $previous["segundos"] < PERFIL_INTERVALO_REENVIO) {
        perfilResponder(200, $answer);
    }

    $code = perfilNovoCodigo();
    perfilCriarToken($conexao, $userId, PASSWORD_RECOVERY_TOKEN_TYPE, password_hash($code, PASSWORD_DEFAULT));

    if (!enviarEmailBrevo($email, "Recuperação de senha - Accuracy", perfilEmailCodigo("Recuperação de senha", $code))) {
        // An unsent code is cancelled, otherwise the resend interval would answer "sent" without sending.
        $created = perfilBuscarToken($conexao, $userId, PASSWORD_RECOVERY_TOKEN_TYPE);
        if ($created) {
            perfilUsarToken($conexao, $created["id_token"]);
        }
        perfilResponder(502, ["success" => false, "message" => "Não foi possível enviar o e-mail agora. Tente de novo em alguns minutos."]);
    }

    perfilResponder(200, $answer);
}

/**
 * POST auth/redefinirSenha {email_usuario, codigo, nova_senha}: changes the password, confirms the
 * e-mail (the code arrived there) and signs out every device.
 */
function resetPasswordController(): void
{
    global $conexao;
    $body = perfilEntrada();
    $email = mb_strtolower(trim((string) ($body["email_usuario"] ?? "")), "UTF-8");
    $code = trim((string) ($body["codigo"] ?? ""));
    $newPassword = (string) ($body["nova_senha"] ?? "");

    if (strlen($newPassword) < PERFIL_SENHA_MIN) {
        perfilResponder(400, ["success" => false, "message" => "A nova senha precisa ter pelo menos " . PERFIL_SENHA_MIN . " caracteres."]);
    }
    if (!preg_match("/^[0-9]{6}$/", $code)) {
        perfilResponder(400, ["success" => false, "message" => "O código tem 6 números."]);
    }

    $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? findByEmailService($conexao, $email) : null;
    if (!$user) {
        perfilResponder(400, ["success" => false, "message" => "Código expirado. Peça um novo."]);
    }

    $userId = (int) $user["id_usuario"];
    $token = perfilConferirCodigo($conexao, $userId, PASSWORD_RECOVERY_TOKEN_TYPE, $code);

    perfilAtualizarSenha($conexao, $userId, password_hash($newPassword, PASSWORD_DEFAULT));
    perfilUsarToken($conexao, $token["id_token"]);
    $conexao->prepare("UPDATE usuario SET email_verificado_usuario = TRUE WHERE id_usuario = :id")
        ->execute([":id" => $userId]);
    perfilRevogarSessoes($conexao, $userId);

    perfilResponder(200, ["success" => true, "message" => "Senha redefinida. Entre com a nova senha."]);
}
