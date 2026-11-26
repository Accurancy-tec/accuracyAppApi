<?php

// Sessões de login (uma por aparelho/navegador): criação com nome do aparelho, conferência a
// cada requisição e encerramento de uma ou de todas pelo próprio usuário.

const SESSAO_DURACAO_SEGUNDOS = 60 * 60 * 24 * 30;
const SESSAO_INTERVALO_ULTIMO_USO_MIN = 5;
const SESSAO_PLATAFORMAS = ["Android", "Site"];

/** Nome curto e seguro para mostrar na lista de aparelhos. */
function sessaoNomeDispositivo(?string $informado, string $plataforma, string $userAgent = ""): string
{
    $nome = trim(preg_replace('/[^\p{L}\p{N} .,·()_\/+-]/u', '', (string) $informado));
    $nome = mb_substr(preg_replace('/\s+/u', ' ', $nome), 0, 80, "UTF-8");
    if ($nome !== "") {
        return $nome;
    }
    if ($plataforma === "Site") {
        return sessaoNomeNavegador($userAgent);
    }
    return "Celular Android";
}

/** "Chrome no Windows", "Safari no iPhone"... a partir do User-Agent. */
function sessaoNomeNavegador(string $userAgent): string
{
    $navegadores = [
        "Edg/" => "Edge", "OPR/" => "Opera", "SamsungBrowser" => "Samsung Internet",
        "Firefox/" => "Firefox", "Chrome/" => "Chrome", "Safari/" => "Safari",
    ];
    $sistemas = [
        "Android" => "Android", "iPhone" => "iPhone", "iPad" => "iPad", "Windows" => "Windows",
        "Mac OS X" => "Mac", "CrOS" => "Chromebook", "Linux" => "Linux",
    ];

    $navegador = "Navegador";
    foreach ($navegadores as $marca => $nome) {
        if (stripos($userAgent, $marca) !== false) {
            $navegador = $nome;
            break;
        }
    }
    foreach ($sistemas as $marca => $nome) {
        if (stripos($userAgent, $marca) !== false) {
            return "$navegador no $nome";
        }
    }
    return $navegador;
}

/** Cria a sessão do login e devolve o id dela (vai no token de acesso como "sid"). */
function sessaoRegistrar(PDO $conexao, int $idUsuario, string $refreshTokenHash, string $plataforma, string $dispositivo): int
{
    $stmt = $conexao->prepare("
        INSERT INTO sessao_usuario (id_usuario, refresh_token_hash, dispositivo_sessao, plataforma_sessao, expira_em, ultimo_uso_em)
        VALUES (:usuario, :hash, :dispositivo, :plataforma, :expira, NOW())
        RETURNING id_sessao
    ");
    $stmt->execute([
        ":usuario" => $idUsuario,
        ":hash" => $refreshTokenHash,
        ":dispositivo" => $dispositivo,
        ":plataforma" => in_array($plataforma, SESSAO_PLATAFORMAS, true) ? $plataforma : null,
        ":expira" => date("Y-m-d H:i:s", time() + SESSAO_DURACAO_SEGUNDOS),
    ]);
    return (int) $stmt->fetchColumn();
}

/**
 * A sessão do token continua valendo? Também anota o último uso (no máximo a cada 5 minutos,
 * para não gravar no banco a cada requisição).
 */
function sessaoAtiva(PDO $conexao, int $idSessao, int $idUsuario): bool
{
    $stmt = $conexao->prepare("
        SELECT (revogado_em IS NULL AND expira_em > NOW()) AS ativa
          FROM sessao_usuario
         WHERE id_sessao = :sessao AND id_usuario = :usuario
    ");
    $stmt->execute([":sessao" => $idSessao, ":usuario" => $idUsuario]);
    $ativa = $stmt->fetchColumn();
    if ($ativa !== true && $ativa !== "t" && $ativa !== 1) {
        return false;
    }

    $conexao->prepare("
        UPDATE sessao_usuario SET ultimo_uso_em = NOW()
         WHERE id_sessao = :sessao
           AND (ultimo_uso_em IS NULL OR ultimo_uso_em < NOW() - make_interval(mins => :minutos))
    ")->execute([":sessao" => $idSessao, ":minutos" => SESSAO_INTERVALO_ULTIMO_USO_MIN]);
    return true;
}

/** Aparelhos com sessão aberta, do uso mais recente para o mais antigo. */
function sessaoListarAtivas(PDO $conexao, int $idUsuario): array
{
    $stmt = $conexao->prepare("
        SELECT id_sessao,
               COALESCE(NULLIF(dispositivo_sessao, ''), 'Aparelho') AS dispositivo,
               plataforma_sessao AS plataforma,
               to_char(criado_em, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS criado_em,
               to_char(COALESCE(ultimo_uso_em, criado_em), 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS ultimo_uso_em
          FROM sessao_usuario
         WHERE id_usuario = :usuario AND revogado_em IS NULL AND expira_em > NOW()
         ORDER BY COALESCE(ultimo_uso_em, criado_em) DESC, id_sessao DESC
    ");
    $stmt->execute([":usuario" => $idUsuario]);
    return array_map(static function (array $s): array {
        $s["id_sessao"] = (int) $s["id_sessao"];
        return $s;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function sessaoEncerrar(PDO $conexao, int $idUsuario, int $idSessao): bool
{
    $stmt = $conexao->prepare("
        UPDATE sessao_usuario SET revogado_em = NOW()
         WHERE id_sessao = :sessao AND id_usuario = :usuario AND revogado_em IS NULL
    ");
    $stmt->execute([":sessao" => $idSessao, ":usuario" => $idUsuario]);
    return $stmt->rowCount() > 0;
}

/** Logout: encerra a sessão dona deste refresh token (o hash é o que fica no banco). */
function sessaoEncerrarPorRefreshToken(PDO $conexao, string $refreshTokenHash): bool
{
    $stmt = $conexao->prepare("
        UPDATE sessao_usuario SET revogado_em = NOW()
         WHERE refresh_token_hash = :hash AND revogado_em IS NULL
    ");
    $stmt->execute([":hash" => $refreshTokenHash]);
    return $stmt->rowCount() > 0;
}

/** Encerra todas as sessões do usuário; com $manter, preserva a do aparelho atual. */
function sessaoEncerrarTodas(PDO $conexao, int $idUsuario, ?int $manter): int
{
    $stmt = $conexao->prepare("
        UPDATE sessao_usuario SET revogado_em = NOW()
         WHERE id_usuario = :usuario AND revogado_em IS NULL
           AND (CAST(:manter AS integer) IS NULL OR id_sessao <> CAST(:manter AS integer))
    ");
    $stmt->bindValue(":usuario", $idUsuario, PDO::PARAM_INT);
    $stmt->bindValue(":manter", $manter, $manter === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}
