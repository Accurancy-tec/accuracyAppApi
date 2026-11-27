<?php

// Login sessions, one per device or browser: created with the device name, checked on every
// request and ended by the user (one device or all of them).

const SESSION_LIFETIME_SECONDS = 60 * 60 * 24 * 30;
const SESSION_LAST_USE_INTERVAL_MIN = 5;
const SESSION_PLATFORMS = ["Android", "Site"];

/** Short, safe name shown in the connected devices list. */
function sessionDeviceName(?string $informed, string $platform, string $userAgent = ""): string
{
    $name = trim(preg_replace('/[^\p{L}\p{N} .,·()_\/+-]/u', '', (string) $informed));
    $name = mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 80, "UTF-8");
    if ($name !== "") {
        return $name;
    }
    if ($platform === "Site") {
        return sessionBrowserName($userAgent);
    }
    return "Celular Android";
}

/** "Chrome no Windows", "Safari no iPhone"... from the User-Agent. */
function sessionBrowserName(string $userAgent): string
{
    $browsers = [
        "Edg/" => "Edge", "OPR/" => "Opera", "SamsungBrowser" => "Samsung Internet",
        "Firefox/" => "Firefox", "Chrome/" => "Chrome", "Safari/" => "Safari",
    ];
    $systems = [
        "Android" => "Android", "iPhone" => "iPhone", "iPad" => "iPad", "Windows" => "Windows",
        "Mac OS X" => "Mac", "CrOS" => "Chromebook", "Linux" => "Linux",
    ];

    $browser = "Navegador";
    foreach ($browsers as $mark => $name) {
        if (stripos($userAgent, $mark) !== false) {
            $browser = $name;
            break;
        }
    }
    foreach ($systems as $mark => $name) {
        if (stripos($userAgent, $mark) !== false) {
            return "$browser no $name";
        }
    }
    return $browser;
}

/** Creates the login session and returns its id (the access token carries it as "sid"). */
function createSession(PDO $connection, int $userId, string $refreshTokenHash, string $platform, string $device): int
{
    $statement = $connection->prepare("
        INSERT INTO sessao_usuario (id_usuario, refresh_token_hash, dispositivo_sessao, plataforma_sessao, expira_em, ultimo_uso_em)
        VALUES (:user_id, :hash, :device, :platform, :expires_at, NOW())
        RETURNING id_sessao
    ");
    $statement->execute([
        ":user_id" => $userId,
        ":hash" => $refreshTokenHash,
        ":device" => $device,
        ":platform" => in_array($platform, SESSION_PLATFORMS, true) ? $platform : null,
        ":expires_at" => date("Y-m-d H:i:s", time() + SESSION_LIFETIME_SECONDS),
    ]);
    return (int) $statement->fetchColumn();
}

/** Whether the session of the token is still valid; also records the last use (at most every 5 minutes). */
function isSessionActive(PDO $connection, int $sessionId, int $userId): bool
{
    $statement = $connection->prepare("
        SELECT (revogado_em IS NULL AND expira_em > NOW()) AS active
          FROM sessao_usuario
         WHERE id_sessao = :session_id AND id_usuario = :user_id
    ");
    $statement->execute([":session_id" => $sessionId, ":user_id" => $userId]);
    $active = $statement->fetchColumn();
    if ($active !== true && $active !== "t" && $active !== 1) {
        return false;
    }

    $connection->prepare("
        UPDATE sessao_usuario SET ultimo_uso_em = NOW()
         WHERE id_sessao = :session_id
           AND (ultimo_uso_em IS NULL OR ultimo_uso_em < NOW() - make_interval(mins => :minutes))
    ")->execute([":session_id" => $sessionId, ":minutes" => SESSION_LAST_USE_INTERVAL_MIN]);
    return true;
}

/** Devices with an open session, most recently used first. */
function listActiveSessions(PDO $connection, int $userId): array
{
    $statement = $connection->prepare("
        SELECT id_sessao,
               COALESCE(NULLIF(dispositivo_sessao, ''), 'Aparelho') AS dispositivo,
               plataforma_sessao AS plataforma,
               to_char(criado_em, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS criado_em,
               to_char(COALESCE(ultimo_uso_em, criado_em), 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS ultimo_uso_em
          FROM sessao_usuario
         WHERE id_usuario = :user_id AND revogado_em IS NULL AND expira_em > NOW()
         ORDER BY COALESCE(ultimo_uso_em, criado_em) DESC, id_sessao DESC
    ");
    $statement->execute([":user_id" => $userId]);
    return array_map(static function (array $session): array {
        $session["id_sessao"] = (int) $session["id_sessao"];
        return $session;
    }, $statement->fetchAll(PDO::FETCH_ASSOC));
}

function endSession(PDO $connection, int $userId, int $sessionId): bool
{
    $statement = $connection->prepare("
        UPDATE sessao_usuario SET revogado_em = NOW()
         WHERE id_sessao = :session_id AND id_usuario = :user_id AND revogado_em IS NULL
    ");
    $statement->execute([":session_id" => $sessionId, ":user_id" => $userId]);
    return $statement->rowCount() > 0;
}

/** Logout: ends the session that owns this refresh token (the database keeps only its hash). */
function endSessionByRefreshToken(PDO $connection, string $refreshTokenHash): bool
{
    $statement = $connection->prepare("
        UPDATE sessao_usuario SET revogado_em = NOW()
         WHERE refresh_token_hash = :hash AND revogado_em IS NULL
    ");
    $statement->execute([":hash" => $refreshTokenHash]);
    return $statement->rowCount() > 0;
}

/** Ends every session of the user; with $keepSessionId, the current device stays signed in. */
function endAllSessions(PDO $connection, int $userId, ?int $keepSessionId): int
{
    $statement = $connection->prepare("
        UPDATE sessao_usuario SET revogado_em = NOW()
         WHERE id_usuario = :user_id AND revogado_em IS NULL
           AND (CAST(:keep AS integer) IS NULL OR id_sessao <> CAST(:keep AS integer))
    ");
    $statement->bindValue(":user_id", $userId, PDO::PARAM_INT);
    $statement->bindValue(":keep", $keepSessionId, $keepSessionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $statement->execute();
    return $statement->rowCount();
}
