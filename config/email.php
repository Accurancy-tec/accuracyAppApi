<?php

function enviarCodigo($email, $codigo)
{
    $apiKey = $_ENV["RESEND_API_KEY"] ?? getenv("RESEND_API_KEY");

    if (empty($apiKey)) {
        error_log("RESEND_API_KEY não configurada.");
        return false;
    }

    $dados = [
        "from" => "Accuracy <onboarding@resend.dev>",
        "to" => [$email],
        "subject" => "Verificação de email - Accuracy",
        "html" => "
            <h2>Verificação de e-mail</h2>
            <p>Olá!</p>
            <p>Seu código de verificação é:</p>
            <h1>$codigo</h1>
            <p>Esse código é válido por 10 minutos.</p>
        "
    ];

    $ch = curl_init("https://api.resend.com/emails");

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($dados),
        CURLOPT_TIMEOUT => 30
    ]);

    $resposta = curl_exec($ch);
    $erro = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($erro) {
        error_log("ERRO RESEND CURL: " . $erro);
        return false;
    }
    if ($status < 200 || $status >= 300) {
        error_log("ERRO RESEND HTTP $status: " . $resposta);

        return false;
    }

    error_log("RESEND SUCESSO: " . $resposta);

    return true;
}
function enviarCodigoBrevo($email, $codigo)
{
    $apiKey    = $_ENV["BREVO_API_KEY"] ?? getenv("BREVO_API_KEY");
    $remetente = $_ENV["BREVO_REMETENTE"] ?? getenv("BREVO_REMETENTE");

    if (empty($apiKey) || empty($remetente)) {
        error_log("BREVO_API_KEY ou BREVO_REMETENTE não configurada.");
        return false;
    }

    $dados = [
        "sender" => ["name" => "Accuracy", "email" => $remetente],
        "to" => [["email" => $email]],
        "subject" => "Verificação de email - Accuracy",
        "htmlContent" => "
            <h2>Verificação de e-mail</h2>
            <p>Olá!</p>
            <p>Seu código de verificação é:</p>
            <h1>$codigo</h1>
            <p>Esse código é válido por 10 minutos.</p>
        "
    ];

    $ch = curl_init("https://api.brevo.com/v3/smtp/email");

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "accept: application/json",
            "content-type: application/json",
            "api-key: " . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($dados),
        CURLOPT_TIMEOUT => 30
    ]);

    $resposta = curl_exec($ch);
    $erro     = curl_error($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($erro) {
        error_log("ERRO BREVO CURL: " . $erro);
        return false;
    }

    if ($status < 200 || $status >= 300) {
        error_log("ERRO BREVO HTTP $status: " . $resposta);
        return false;
    }

    return true;
}