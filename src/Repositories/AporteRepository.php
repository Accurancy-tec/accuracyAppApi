<?php
// Confere se a carteira pertence mesmo ao usuário autenticado,
// pra ninguém criar aporte recorrente em carteira de outra pessoa

use PhpParser\Node\Stmt\Switch_;

function criarAporte(PDO $conexao, $id_carteira, $id_ativo, $tipo_aporte, $quantidade_aporte, $valor_aporte, $recorrencia_aporte, $obs_aporte, $data_aporte)
{

    $sql = $conexao->prepare("INSERT INTO aporte (
    id_carteira, 
    id_ativo, 
    tipo_aporte,
    quantidade_aporte,
    valor_aporte,
    recorrencia_aporte,
    observacao_aporte,
    data_aporte) 
    VALUES (:id_carteira, :id_ativo, :tipo_aporte, :quantidade_aporte, :valor_aporte, :recorrencia_aporte, :obs_aporte, :data_aporte)"
    );

    $sql->execute([
        ':id_carteira' => $id_carteira,
        ':id_ativo' => $id_ativo,
        ':tipo_aporte' => $tipo_aporte,
        ':quantidade_aporte' => $quantidade_aporte,
        ':valor_aporte' => $valor_aporte,
        ':recorrencia_aporte' => $recorrencia_aporte,
        ':obs_aporte' => $obs_aporte,
        ':data_aporte' => $data_aporte
    ]);

    return $sql->fetchAll(PDO::FETCH_ASSOC);

}
// Cria um novo aporte recorrente pra uma carteira/ativo
function criarAporteRecorrente(PDO $conexao, $idCarteira, $idAtivo, $valorRecorrente, $frequenciaRecorrente, $diaReferenciaRecorrente, $proximaExecucaoRecorrente)
{
    $sql = "INSERT INTO aporte_recorrente
                (id_carteira, id_ativo, valor_recorrente, frequencia_recorrente, dia_referencia_recorrente, ativo_flag_recorrente, proxima_execucao_recorrente)
            VALUES
                (:carteira, :ativo, :valor, :frequencia, :dia, TRUE, :proxima)
            RETURNING id_aporte_recorrente";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':carteira', $idCarteira);
    $stmt->bindParam(':ativo', $idAtivo);
    $stmt->bindParam(':valor', $valorRecorrente);
    $stmt->bindParam(':frequencia', $frequenciaRecorrente);
    $stmt->bindParam(':dia', $diaReferenciaRecorrente);
    $stmt->bindParam(':proxima', $proximaExecucaoRecorrente);
    $stmt->execute();

    $novo = $stmt->fetch(PDO::FETCH_ASSOC);

    return $novo["id_aporte_recorrente"];
}

// Lista os aportes recorrentes de uma carteira
function listarAporteRecorrente(PDO $conexao, $idCarteira)
{
    $sql = "SELECT r.id_aporte_recorrente, r.id_carteira, r.id_ativo, a.simbolo_ativo, a.nome_ativo,
                   r.valor_recorrente, r.frequencia_recorrente, r.dia_referencia_recorrente,
                   r.ativo_flag_recorrente, r.proxima_execucao_recorrente, r.criado_em
            FROM aporte_recorrente r
            JOIN ativo a ON a.id_ativo = r.id_ativo
            WHERE r.id_carteira = :carteira
            ORDER BY r.criado_em DESC";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':carteira', $idCarteira);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Atualiza valor, frequência, dia de referência e status (ativo/inativo) de um aporte recorrente
function atualizarAporteRecorrente(PDO $conexao, $idAporteRecorrente, $valorRecorrente, $frequenciaRecorrente, $diaReferenciaRecorrente, $ativoFlagRecorrente)
{
    $sql = "UPDATE aporte_recorrente
            SET valor_recorrente = :valor,
                frequencia_recorrente = :frequencia,
                dia_referencia_recorrente = :dia,
                ativo_flag_recorrente = :ativoFlag
            WHERE id_aporte_recorrente = :id";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':valor', $valorRecorrente);
    $stmt->bindParam(':frequencia', $frequenciaRecorrente);
    $stmt->bindParam(':dia', $diaReferenciaRecorrente);
    $stmt->bindParam(':ativoFlag', $ativoFlagRecorrente, PDO::PARAM_INT);
    $stmt->bindParam(':id', $idAporteRecorrente);
    $stmt->execute();

    return $stmt->rowCount() > 0;
}

function listarAportesUsuario(PDO $conexao, $id_usuario, $id_carteira, $limite = 200)
{
    $limite = max(1, (int) $limite);

    $sql = $conexao->prepare("
        SELECT
            a.id_aporte,
            at.simbolo_ativo AS ativo_aporte,
            at.nome_ativo AS name_ativo,
            at.categoria_ativo AS categoria_ativo,
            a.quantidade_aporte,
            a.valor_aporte,
            a.tipo_aporte,
            a.recorrencia_aporte
        FROM Aporte a
        INNER JOIN Carteira c ON c.id_carteira = a.id_carteira
        INNER JOIN Ativo at ON at.id_ativo = a.id_ativo
        WHERE c.id_usuario = :id_usuario
        AND c.id_carteira = :id_carteira
        ORDER BY a.id_aporte DESC
        LIMIT $limite
    ");

    $sql->execute([":id_usuario" => $id_usuario, ":id_carteira" => $id_carteira]);

    return $sql->fetchAll(PDO::FETCH_ASSOC);


}

function getIdAporteRecorrente(PDO $conexao, $idAporteRecorrente)
{
    $sql = "SELECT id_carteira FROM aporte_recorrente WHERE id_aporte_recorrente = ? ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idAporteRecorrente]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAportesRecorrentes(PDO $conexao)
{
    $sql = "
        SELECT
            id_aporte_recorrente,
            id_carteira,
            id_ativo,
            valor_recorrente,
            frequencia_recorrente,
            proxima_execucao_recorrente
        FROM aporte_recorrente
        WHERE ativo_flag_recorrente = TRUE
         AND proxima_execucao_recorrente <= CURRENT_DATE
        ORDER BY proxima_execucao_recorrente
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateAporteRecorrente(PDO $conexao, $proximaExecucao, $idAporteRecorrente)
{

    // Atualiza o aporte recorrente
    $sqlUpdate = "UPDATE aporte_recorrente SET proxima_execucao_recorrente = :proxima WHERE id_aporte_recorrente = :id";

    $update = $conexao->prepare($sqlUpdate);

    $update->execute([
        ":proxima" => $proximaExecucao,
        ":id" => $idAporteRecorrente
    ]);

    return $update;

}

function excluirAporteRepository(PDO $conexao, $idAporte, $idUsuario)
{
    try {

        $conexao->beginTransaction();

        // 1. Busca o aporte e confirma que ele pertence ao usuário
        $sqlAporte = "
            SELECT
                a.id_aporte,
                a.id_carteira,
                a.id_ativo
            FROM aporte a
            INNER JOIN carteira c
                ON c.id_carteira = a.id_carteira
            WHERE a.id_aporte = ?
              AND c.id_usuario = ?
            FOR UPDATE
        ";

        $stmtAporte = $conexao->prepare($sqlAporte);
        $stmtAporte->execute([$idAporte, $idUsuario]);

        $aporte = $stmtAporte->fetch(PDO::FETCH_ASSOC);

        if (!$aporte) {
            $conexao->rollBack();
            return false;
        }

        $idCarteira = (int) $aporte["id_carteira"];
        $idAtivo = (int) $aporte["id_ativo"];

        // 2. Exclui o aporte
        $sqlDelete = "
            DELETE FROM aporte
            WHERE id_aporte = ?
        ";

        $stmtDelete = $conexao->prepare($sqlDelete);
        $stmtDelete->execute([$idAporte]);

        // 3. Recalcula a posição desse ativo nessa carteira
        recalcularPosicaoAposExclusao(
            $conexao,
            $idCarteira,
            $idAtivo
        );

        // 4. Confirma tudo
        $conexao->commit();

        return true;

    } catch (Throwable $erro) {

        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }

        throw $erro;
    }
}
function recalcularPosicaoAposExclusao(PDO $conexao, $idCarteira, $idAtivo)
{
    /*
     * Recalcula a posição usando todos os aportes que
     * continuam cadastrados para essa carteira e ativo.
     *
     * Compra    -> aumenta quantidade e valor investido
     * Venda     -> diminui quantidade
     * Dividendo -> não altera quantidade
     */

    $sql = "
        SELECT
            COALESCE(
                SUM(
                    CASE
                        WHEN tipo_aporte = 'Compra'
                        THEN quantidade_aporte
                        WHEN tipo_aporte = 'Venda'
                        THEN -quantidade_aporte
                        ELSE 0
                    END
                ),
                0
            ) AS quantidade,

            COALESCE(
                SUM(
                    CASE
                        WHEN tipo_aporte = 'Compra'
                        THEN valor_aporte
                        ELSE 0
                    END
                ),
                0
            ) AS valor_compras
        FROM aporte
        WHERE id_carteira = ?
          AND id_ativo = ?
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idCarteira, $idAtivo]);

    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    $quantidade = (float) ($resultado["quantidade"] ?? 0);
    $valorCompras = (float) ($resultado["valor_compras"] ?? 0);

    // Não pode existir posição negativa
    if ($quantidade < 0) {
        $quantidade = 0;
    }

    // Se não existe mais quantidade desse ativo,
    // remove a posição da carteira.
    if ($quantidade <= 0) {

        $sqlDeletePosicao = "
            DELETE FROM item_investimento
            WHERE id_carteira = ?
              AND id_ativo = ?
        ";

        $stmtDeletePosicao = $conexao->prepare($sqlDeletePosicao);
        $stmtDeletePosicao->execute([
            $idCarteira,
            $idAtivo
        ]);

        return;
    }

    // Calcula novamente o preço médio das compras restantes
    $precoMedio = $valorCompras / $quantidade;

    // Atualiza a posição existente
    $sqlUpdate = "
        UPDATE item_investimento
        SET
            quantidade_item = ?,
            preco_medio_item = ?,
            atualizado_em = NOW()
        WHERE id_carteira = ?
          AND id_ativo = ?
    ";

    $stmtUpdate = $conexao->prepare($sqlUpdate);
    $stmtUpdate->execute([
        $quantidade,
        $precoMedio,
        $idCarteira,
        $idAtivo
    ]);

    // Caso por algum motivo a posição não exista,
    // cria novamente a partir dos aportes restantes.
    if ($stmtUpdate->rowCount() === 0) {

        $sqlInsert = "
            INSERT INTO item_investimento
                (
                    id_carteira,
                    id_ativo,
                    quantidade_item,
                    preco_medio_item,
                    atualizado_em
                )
            VALUES (?, ?, ?, ?, NOW())
            ON CONFLICT (id_carteira, id_ativo)
            DO UPDATE SET
                quantidade_item = EXCLUDED.quantidade_item,
                preco_medio_item = EXCLUDED.preco_medio_item,
                atualizado_em = NOW()
        ";

        $stmtInsert = $conexao->prepare($sqlInsert);
        $stmtInsert->execute([
            $idCarteira,
            $idAtivo,
            $quantidade,
            $precoMedio
        ]);
    }
}

function listarAportesDestaque(PDO $conexao, $idUsuario, $Choise)
{
    switch ($Choise) {

        case 'Mais caros':
            $sql = "SELECT
                        a.id_carteira,
                        atv.simbolo_ativo   AS ativo_aporte,
                        atv.nome_ativo      AS name_ativo,
                        atv.categoria_ativo AS categoria_ativo,
                        a.quantidade_aporte AS quantidade_aporte,
                        a.valor_aporte      AS valor_aporte,
                        a.tipo_aporte       AS tipo_aporte,
                        a.recorrencia_aporte AS recorrencia_aporte
                    FROM Aporte a
                    JOIN Carteira c ON c.id_carteira = a.id_carteira
                    JOIN Ativo atv  ON atv.id_ativo = a.id_ativo
                    WHERE c.id_usuario = ?
                      AND a.status_aporte = 'Concluída'
                      AND a.tipo_aporte = 'Compra'
                    ORDER BY a.valor_aporte DESC
                    LIMIT 4";
                    
            break;

        case 'Mais lucrativos':
            $sql = "SELECT
                MIN(ii.id_carteira)  AS id_carteira,
                atv.simbolo_ativo    AS ativo_aporte,
                atv.nome_ativo       AS name_ativo,
                atv.categoria_ativo  AS categoria_ativo,
                SUM(ii.quantidade_item) AS quantidade_aporte,
                SUM(ii.quantidade_item *
                    (cot.preco_fechamento_cotacao - ii.preco_medio_item)) AS valor_aporte,
                'Lucro'              AS tipo_aporte,
                ''                   AS recorrencia_aporte
            FROM Item_Investimento ii
            JOIN Carteira c ON c.id_carteira = ii.id_carteira
            JOIN Ativo atv  ON atv.id_ativo = ii.id_ativo
            JOIN Cotacao_Ativo cot ON cot.id_ativo = ii.id_ativo
             AND cot.data_cotacao = (
                 SELECT MAX(c2.data_cotacao)
                 FROM Cotacao_Ativo c2
                 WHERE c2.id_ativo = ii.id_ativo)
            WHERE c.id_usuario = ?
            GROUP BY atv.id_ativo, atv.simbolo_ativo, atv.nome_ativo, atv.categoria_ativo
            HAVING SUM(ii.quantidade_item * (cot.preco_fechamento_cotacao - ii.preco_medio_item)) > 0
            ORDER BY valor_aporte DESC
            LIMIT 4";

            break;

        case 'Maiores perdas':
            $sql = "SELECT
                MIN(ii.id_carteira)  AS id_carteira,
                atv.simbolo_ativo    AS ativo_aporte,
                atv.nome_ativo       AS name_ativo,
                atv.categoria_ativo  AS categoria_ativo,
                SUM(ii.quantidade_item) AS quantidade_aporte,
                SUM(ii.quantidade_item *
                    (cot.preco_fechamento_cotacao - ii.preco_medio_item)) AS valor_aporte,
                'Perda'              AS tipo_aporte,
                ''                   AS recorrencia_aporte
            FROM Item_Investimento ii
            JOIN Carteira c ON c.id_carteira = ii.id_carteira
            JOIN Ativo atv  ON atv.id_ativo = ii.id_ativo
            JOIN Cotacao_Ativo cot ON cot.id_ativo = ii.id_ativo
             AND cot.data_cotacao = (
                 SELECT MAX(c2.data_cotacao)
                 FROM Cotacao_Ativo c2
                 WHERE c2.id_ativo = ii.id_ativo)
            WHERE c.id_usuario = ?
            GROUP BY atv.id_ativo, atv.simbolo_ativo, atv.nome_ativo, atv.categoria_ativo
            HAVING SUM(ii.quantidade_item * (cot.preco_fechamento_cotacao - ii.preco_medio_item)) < 0
            ORDER BY valor_aporte ASC
            LIMIT 4";

            break;

        default:
            throw new InvalidArgumentException("Filtro inválido: " . $Choise);
    }

    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idUsuario]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function buscarRecorrenteAtivoIgual(PDO $conexao, $idCarteira, $idAtivo, $frequencia)
{
    $stmt = $conexao->prepare("SELECT id_aporte_recorrente FROM aporte_recorrente
        WHERE id_carteira = ? AND id_ativo = ? AND frequencia_recorrente = ?
        AND ativo_flag_recorrente::int = 1 LIMIT 1");
    $stmt->execute([$idCarteira, $idAtivo, $frequencia]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}