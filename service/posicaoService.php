<?php

// Atualiza item_investimento da carteira, depois do aporte
 
function atualizarPosicao(PDO $conexao, $idCarteira, $idAtivo, $tipoAporte, $quantidade, $valorTotal)
{
    if ($tipoAporte === 'compra') {

        $precoUnitario = $quantidade > 0 ? $valorTotal / $quantidade : 0;

        $sql = "INSERT INTO item_investimento (id_carteira, id_ativo, quantidade_item, preco_medio_item, atualizado_em)
                VALUES (:carteira, :ativo, :quantidade, :preco, NOW())
                ON CONFLICT (id_carteira, id_ativo) DO UPDATE SET
                    preco_medio_item = (
                        (item_investimento.quantidade_item * item_investimento.preco_medio_item)
                        + (EXCLUDED.quantidade_item * EXCLUDED.preco_medio_item)
                    ) / (item_investimento.quantidade_item + EXCLUDED.quantidade_item),
                    quantidade_item = item_investimento.quantidade_item + EXCLUDED.quantidade_item,
                    atualizado_em = NOW()";

        $stmt = $conexao->prepare($sql);
        $stmt->bindParam(':carteira', $idCarteira);
        $stmt->bindParam(':ativo', $idAtivo);
        $stmt->bindParam(':quantidade', $quantidade);
        $stmt->bindParam(':preco', $precoUnitario);
        $stmt->execute();

        return true;
    }

    // reduz a quantidade condição impede saldo negativo
    $sql = "UPDATE item_investimento
            SET quantidade_item = quantidade_item - :quantidade,
                atualizado_em = NOW()
            WHERE id_carteira = :carteira AND id_ativo = :ativo AND quantidade_item >= :quantidade";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':carteira', $idCarteira);
    $stmt->bindParam(':ativo', $idAtivo);
    $stmt->bindParam(':quantidade', $quantidade);
    $stmt->execute();

    return $stmt->rowCount() > 0;
}


// traz símbolo e nome do ativo.

function buscarPosicoes(PDO $conexao, $idCarteira)
{
    $sql = "SELECT i.id_carteira, i.id_ativo, a.simbolo_ativo, a.nome_ativo,
                   i.quantidade_item, i.preco_medio_item, i.atualizado_em
            FROM item_investimento i
            JOIN ativo a ON a.id_ativo = i.id_ativo
            WHERE i.id_carteira = ?";

    $consulta = $conexao->prepare($sql);
    $consulta->bindParam(1, $idCarteira);
    $consulta->execute();

    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}