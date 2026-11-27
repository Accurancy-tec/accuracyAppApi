<?php
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
            (SELECT COUNT(*) FROM atividade a WHERE a.id_trilha = trilha.id_trilha) AS total_videos_trilha
        FROM trilha
        ORDER BY ordem_trilha, id_trilha
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
            (SELECT COUNT(*) FROM atividade a WHERE a.id_trilha = trilha.id_trilha) AS total_videos_trilha
        FROM trilha
        WHERE id_trilha = ?
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idTrilha]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}
