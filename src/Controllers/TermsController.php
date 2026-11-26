<?php

require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/TermsService.php";

function sendTermsResponse(int $status, array $body): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** POST auth/termos: whether the user still has to accept the current terms. */
function termsStatusController(): void
{
    global $conexao;
    $userId = (int) autenticar();

    try {
        sendTermsResponse(200, ["success" => true] + termsStatus($conexao, $userId));
    } catch (Throwable $error) {
        error_log("Terms status failed: " . $error->getMessage());
        sendTermsResponse(500, ["success" => false, "message" => "Não foi possível consultar os termos agora."]);
    }
}

/** POST auth/aceitar-termos */
function acceptTermsController(): void
{
    global $conexao;
    $userId = (int) autenticar();

    try {
        acceptTerms($conexao, $userId);
    } catch (Throwable $error) {
        error_log("Terms acceptance failed: " . $error->getMessage());
        sendTermsResponse(500, ["success" => false, "message" => "Não foi possível registrar o aceite agora."]);
    }

    sendTermsResponse(200, ["success" => true, "message" => "Termos aceitos.", "version" => TERMS_VERSION]);
}
