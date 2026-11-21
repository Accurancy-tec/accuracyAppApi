<?php
// REPOSITORY = só conversa com o banco (SQL). Nada de regra aqui.

// Lista todas as trilhas (tópicos/playlists) na ordem certa
function buscarTrilhas(PDO $conexao)
{
    $sql = "
        SELECT
            id_trilha,
            titulo_trilha,
            icone_trilha,
            ordem_trilha,
            playlist_youtube_trilha,
            descricao_trilha,
            total_videos_trilha
        FROM trilha
        ORDER BY ordem_trilha, id_trilha
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Busca uma trilha só, pelo id
function buscarTrilhaPorId(PDO $conexao, $idTrilha)
{
    $sql = "
        SELECT
            id_trilha,
            titulo_trilha,
            icone_trilha,
            ordem_trilha,
            playlist_youtube_trilha,
            descricao_trilha,
            total_videos_trilha
        FROM trilha
        WHERE id_trilha = ?
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idTrilha]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}
