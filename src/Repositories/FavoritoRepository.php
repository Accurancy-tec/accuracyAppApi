<?php

// Favorite assets per user (table ativo_favorito). Only the API server touches this table.

const FAVORITOS_LIMITE_POR_USUARIO = 200;

function listarFavoritosRepository(PDO $conexao, int $id_usuario, ?string $categoria = null): array
{
    $sql = 'SELECT simbolo_ativo, nome_ativo, categoria_ativo, criado_em
              FROM ativo_favorito
             WHERE id_usuario = :id_usuario';
    if ($categoria !== null) {
        $sql .= ' AND categoria_ativo = :categoria';
    }
    $sql .= ' ORDER BY criado_em DESC, id_favorito DESC';

    $stmt = $conexao->prepare($sql);
    $stmt->bindValue(':id_usuario', $id_usuario, PDO::PARAM_INT);
    if ($categoria !== null) {
        $stmt->bindValue(':categoria', $categoria);
    }
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function contarFavoritosRepository(PDO $conexao, int $id_usuario): int
{
    $stmt = $conexao->prepare('SELECT count(*) FROM ativo_favorito WHERE id_usuario = :id_usuario');
    $stmt->bindValue(':id_usuario', $id_usuario, PDO::PARAM_INT);
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

/** Idempotent insert. Returns true when a new row was created, false when it already existed. */
function adicionarFavoritoRepository(PDO $conexao, int $id_usuario, string $simbolo, string $nome, string $categoria): bool
{
    $stmt = $conexao->prepare(
        'INSERT INTO ativo_favorito (id_usuario, simbolo_ativo, nome_ativo, categoria_ativo)
         VALUES (:id_usuario, :simbolo, :nome, :categoria)
         ON CONFLICT (id_usuario, simbolo_ativo, categoria_ativo) DO NOTHING'
    );
    $stmt->bindValue(':id_usuario', $id_usuario, PDO::PARAM_INT);
    $stmt->bindValue(':simbolo', $simbolo);
    $stmt->bindValue(':nome', $nome);
    $stmt->bindValue(':categoria', $categoria);
    $stmt->execute();

    return $stmt->rowCount() === 1;
}

/** Idempotent delete. Returns true when a row was removed. */
function removerFavoritoRepository(PDO $conexao, int $id_usuario, string $simbolo, string $categoria): bool
{
    $stmt = $conexao->prepare(
        'DELETE FROM ativo_favorito
          WHERE id_usuario = :id_usuario AND simbolo_ativo = :simbolo AND categoria_ativo = :categoria'
    );
    $stmt->bindValue(':id_usuario', $id_usuario, PDO::PARAM_INT);
    $stmt->bindValue(':simbolo', $simbolo);
    $stmt->bindValue(':categoria', $categoria);
    $stmt->execute();

    return $stmt->rowCount() > 0;
}
