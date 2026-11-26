<?php

/** Notícias já publicadas pelos administradores, mais recentes primeiro. */
function buscarNoticiasPublicadas(PDO $conexao, int $limite): array
{
    $stmt = $conexao->prepare("
        SELECT id_noticia, titulo_noticia, resumo_noticia, conteudo_noticia,
               categoria_noticia::text AS categoria_noticia, publicada_em
        FROM noticia
        WHERE publicada_em IS NOT NULL
          AND publicada_em <= NOW()
        ORDER BY publicada_em DESC
        LIMIT :limite
    ");
    $stmt->bindValue(":limite", $limite, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
