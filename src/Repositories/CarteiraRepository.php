<?php
// Confere se a carteira pertence mesmo ao usuário autenticado,
// pra ninguém criar aporte recorrente em carteira de outra pessoa
function registrarCarteira(PDO $conexao, $idUsuario, $nomeCarteira, $tipoCarteira)
{
    $sql = "INSERT INTO carteira (id_usuario, nome_carteira, tipo_carteira) VALUES (?, ?, ?) RETURNING id_carteira";
    $insert = $conexao->prepare($sql);
    $insert->bindParam(1, $idUsuario);
    $insert->bindParam(2, $nomeCarteira);
    $insert->bindParam(3, $tipoCarteira);
    $insert->execute();

    $nova = $insert->fetch(PDO::FETCH_ASSOC);

    return $nova["id_carteira"];
}

function carteiraPertenceAoUsuario(PDO $conexao, $idCarteira, $idUsuario)
{
    $sql = "SELECT id_carteira FROM carteira WHERE id_carteira = ? AND id_usuario = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idCarteira, $idUsuario]);

    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

function getCarteirasDoUsuario(PDO $conexao, $id_usuario)
{
    $sql = "
            SELECT
                c.id_carteira,
                c.nome_carteira,
                c.tipo_carteira,

                COALESCE(
                    a.total_aportado,
                    0
                ) AS total_aportado

            FROM carteira c

            LEFT JOIN (
                SELECT
                    id_carteira,

                    SUM(
                        CASE
                            WHEN tipo_aporte = 'Compra'
                            THEN valor_aporte
                            ELSE 0
                        END
                    ) AS total_aportado

                FROM aporte

                GROUP BY id_carteira

            ) a ON a.id_carteira = c.id_carteira

            WHERE c.id_usuario = :id_usuario

            ORDER BY c.id_carteira DESC
        ";

    $stmt = $conexao->prepare($sql);

    $stmt->execute([
        ":id_usuario" => $id_usuario
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarPosicoesAcoes(PDO $conexao, $idUsuario)
{
    $sql = "
        SELECT
            c.id_carteira,
            c.nome_carteira,
            ii.id_ativo,
            a.simbolo_ativo AS ticker,
            ii.quantidade_item AS quantidade,
            ii.preco_medio_item AS preco_medio

        FROM carteira c

        INNER JOIN item_investimento ii
            ON ii.id_carteira = c.id_carteira

        INNER JOIN ativo a
            ON a.id_ativo = ii.id_ativo

        WHERE c.id_usuario = :id_usuario
          AND a.categoria_ativo = 'Ações'
          AND ii.quantidade_item > 0

        ORDER BY c.id_carteira, a.simbolo_ativo
    ";

    $stmt = $conexao->prepare($sql);

    $stmt->execute([
        ':id_usuario' => $idUsuario
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}