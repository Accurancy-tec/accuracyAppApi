<?php

function accuracyListarAtividades(PDO $conexao, ?int $idTrilha): array
{
    $sql = 'SELECT a.id_atividade, a.id_trilha, a.id_curso, a.ordem_atividade,
                   c.titulo_curso, c.descricao_curso, c.categoria_curso, c.nivel_curso,
                   c.duracao_minutos_curso, c.url_video_curso, c.url_thumb_curso
            FROM public.atividade a
            JOIN public.curso c ON c.id_curso = a.id_curso';
    $params = [];
    if ($idTrilha !== null) {
        $sql .= ' WHERE a.id_trilha = ?';
        $params[] = $idTrilha;
    }
    $sql .= ' ORDER BY a.id_trilha, a.ordem_atividade, a.id_atividade';
    $stmt = $conexao->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function accuracyConsultarProgresso(PDO $conexao, int $idUsuario, ?int $idTrilha): array
{
    $sql = 'SELECT a.id_atividade, a.id_trilha, a.id_curso, a.ordem_atividade,
                   c.url_video_curso, c.titulo_curso,
                   COALESCE(p.percentual_progresso, 0) AS percentual_progresso,
                   CASE WHEN p.concluido_progresso THEN 1 ELSE 0 END AS concluido_progresso,
                   COALESCE(p.ultima_posicao_segundos, 0) AS ultima_posicao_segundos,
                   p.atualizado_em
            FROM public.atividade a
            JOIN public.curso c ON c.id_curso = a.id_curso
            LEFT JOIN public.item_progresso p ON p.id_atividade = a.id_atividade
                AND p.id_usuario = ?';
    $params = [$idUsuario];
    if ($idTrilha !== null) {
        $sql .= ' WHERE a.id_trilha = ?';
        $params[] = $idTrilha;
    }
    $sql .= ' ORDER BY a.id_trilha, a.ordem_atividade, a.id_atividade';
    $stmt = $conexao->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function accuracyGravarProgresso(PDO $conexao, int $idUsuario, int $idAtividade, int $percentual, int $posicao)
{
    $sql = 'INSERT INTO public.item_progresso AS p
                (id_usuario, id_atividade, percentual_progresso, concluido_progresso,
                 ultima_posicao_segundos, atualizado_em)
            SELECT ?, a.id_atividade, ?, CAST(? AS integer) >= 80, ?, CURRENT_TIMESTAMP
            FROM public.atividade a WHERE a.id_atividade = ?
            ON CONFLICT (id_usuario, id_atividade) DO UPDATE SET
                percentual_progresso = GREATEST(p.percentual_progresso, EXCLUDED.percentual_progresso),
                concluido_progresso = p.concluido_progresso OR EXCLUDED.concluido_progresso,
                ultima_posicao_segundos = EXCLUDED.ultima_posicao_segundos,
                atualizado_em = CURRENT_TIMESTAMP
            RETURNING id_atividade, percentual_progresso,
                CASE WHEN concluido_progresso THEN 1 ELSE 0 END AS concluido_progresso,
                ultima_posicao_segundos, atualizado_em';
    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idUsuario, $percentual, $percentual, $posicao, $idAtividade]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}