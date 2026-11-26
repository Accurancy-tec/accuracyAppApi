<?php

function inserirNotificacao(PDO $conexao, int $idUsuario, string $titulo, string $mensagem, string $tipo): void
{
    $stmt = $conexao->prepare("
        INSERT INTO notificacao (id_usuario, titulo_notificacao, mensagem_notificacao, tipo_notificacao)
        VALUES (:usuario, :titulo, :mensagem, CAST(:tipo AS tipo_notificacao_enum))
    ");
    $stmt->execute([
        ":usuario" => $idUsuario,
        ":titulo" => mb_substr($titulo, 0, 120, "UTF-8"),
        ":mensagem" => mb_substr($mensagem, 0, 255, "UTF-8"),
        ":tipo" => $tipo,
    ]);
}

function listarNotificacoesUsuario(PDO $conexao, int $idUsuario, int $limite): array
{
    $stmt = $conexao->prepare("
        SELECT id_notificacao,
               titulo_notificacao AS titulo,
               mensagem_notificacao AS mensagem,
               tipo_notificacao::text AS tipo,
               lida_notificacao AS lida,
               to_char(criado_em, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS criado_em
        FROM notificacao
        WHERE id_usuario = :usuario
        ORDER BY criado_em DESC, id_notificacao DESC
        LIMIT :limite
    ");
    $stmt->bindValue(":usuario", $idUsuario, PDO::PARAM_INT);
    $stmt->bindValue(":limite", $limite, PDO::PARAM_INT);
    $stmt->execute();

    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($linhas as &$linha) {
        $linha["id_notificacao"] = (int) $linha["id_notificacao"];
        $linha["lida"] = $linha["lida"] === true || $linha["lida"] === "t" || $linha["lida"] === 1;
    }
    unset($linha);

    return $linhas;
}

function contarNotificacoesNaoLidas(PDO $conexao, int $idUsuario): int
{
    $stmt = $conexao->prepare("
        SELECT COUNT(*) FROM notificacao
        WHERE id_usuario = :usuario AND lida_notificacao = FALSE
    ");
    $stmt->execute([":usuario" => $idUsuario]);

    return (int) $stmt->fetchColumn();
}

/** Marca uma notificação (ou todas, quando $idNotificacao é null) como lida. Devolve quantas mudaram. */
function marcarNotificacoesLidas(PDO $conexao, int $idUsuario, ?int $idNotificacao): int
{
    $sql = "UPDATE notificacao SET lida_notificacao = TRUE
            WHERE id_usuario = :usuario AND lida_notificacao = FALSE";
    $parametros = [":usuario" => $idUsuario];

    if ($idNotificacao !== null) {
        $sql .= " AND id_notificacao = :id";
        $parametros[":id"] = $idNotificacao;
    }

    $stmt = $conexao->prepare($sql);
    $stmt->execute($parametros);

    return $stmt->rowCount();
}

/** Carteiras com meta alcançada que ainda não geraram aviso; marca a data para não repetir. */
function registrarMetaAtingida(PDO $conexao, int $idCarteira): bool
{
    $stmt = $conexao->prepare("
        UPDATE carteira SET meta_atingida_em = NOW()
        WHERE id_carteira = :carteira AND meta_atingida_em IS NULL AND meta_carteira > 0
    ");
    $stmt->execute([":carteira" => $idCarteira]);

    return $stmt->rowCount() > 0;
}
