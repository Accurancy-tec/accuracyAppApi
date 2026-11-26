<?php

require_once __DIR__ . "/AuthController.php";
require_once __DIR__ . "/../Services/SessionService.php";

/** POST auth/sessoes: devices with an open login; "atual" marks this device, which comes first. */
function listSessionsController(): void
{
    global $conexao;
    $userId = (int) autenticar();
    $currentSessionId = $GLOBALS["current_session_id"] ?? null;

    $sessions = array_map(static function (array $session) use ($currentSessionId): array {
        $session["atual"] = $currentSessionId !== null && $session["id_sessao"] === (int) $currentSessionId;
        return $session;
    }, listActiveSessions($conexao, $userId));
    usort($sessions, static fn(array $a, array $b): int => (int) $b["atual"] <=> (int) $a["atual"]);

    perfilResponder(200, ["success" => true, "sessoes" => $sessions]);
}

/** POST auth/sessaoEncerrar {id_sessao}: signs one device out. */
function endSessionController(): void
{
    global $conexao;
    $userId = (int) autenticar();
    $sessionId = filter_var(perfilEntrada()["id_sessao"] ?? null, FILTER_VALIDATE_INT);

    if (!$sessionId || $sessionId <= 0) {
        perfilResponder(400, ["success" => false, "message" => "Informe o aparelho que será desconectado."]);
    }
    if (!endSession($conexao, $userId, $sessionId)) {
        perfilResponder(404, ["success" => false, "message" => "Esse aparelho já estava desconectado."]);
    }

    $currentSessionId = $GLOBALS["current_session_id"] ?? null;
    perfilResponder(200, [
        "success" => true,
        "message" => "Aparelho desconectado.",
        "encerrou_atual" => $currentSessionId !== null && (int) $currentSessionId === $sessionId,
    ]);
}

/** POST auth/sessoesEncerrar {manter_atual}: signs out every other device (or all of them). */
function endAllSessionsController(): void
{
    global $conexao;
    $userId = (int) autenticar();
    $currentSessionId = $GLOBALS["current_session_id"] ?? null;
    $keepCurrent = (perfilEntrada()["manter_atual"] ?? true) !== false;

    $ended = endAllSessions($conexao, $userId, $keepCurrent && $currentSessionId !== null ? (int) $currentSessionId : null);

    perfilResponder(200, [
        "success" => true,
        "message" => $keepCurrent ? "Os outros aparelhos foram desconectados." : "Todos os aparelhos foram desconectados.",
        "encerradas" => $ended,
        "encerrou_atual" => !$keepCurrent || $currentSessionId === null,
    ]);
}

/**
 * POST auth/sair {refresh_token}: logout of this device on the server. It uses the refresh token,
 * so it works after the access token expired, and always answers the same.
 */
function logoutController(): void
{
    global $conexao;
    $refreshToken = trim((string) (perfilEntrada()["refresh_token"] ?? ""));

    if ($refreshToken !== "") {
        endSessionByRefreshToken($conexao, criarHashRefreshToken($refreshToken));
    }

    perfilResponder(200, ["success" => true, "message" => "Você saiu da conta neste aparelho."]);
}
