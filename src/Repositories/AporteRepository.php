<?php

require_once __DIR__ . '/DetalheAporteRepository.php';

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
    VALUES (:id_carteira, :id_ativo, :tipo_aporte, :quantidade_aporte, :valor_aporte, :recorrencia_aporte, :obs_aporte, :data_aporte)
    RETURNING id_aporte"
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

    return anexarDetalhesAportes($conexao, $sql->fetchAll(PDO::FETCH_ASSOC));


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
         AND id_carteira IN (SELECT id_carteira FROM carteira WHERE deleted_at IS NULL)
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

/**
 * Exclui um aporte desfazendo o efeito dele:
 *  - a posição do ativo é recalculada com as operações que sobraram;
 *  - o dinheiro volta (compra) ou sai (venda/dividendo) do saldo de onde veio:
 *    saldo geral nas carteiras reais, saldo da simulação nas simuladas.
 *
 * Bloqueia exclusões que deixariam a carteira inconsistente (compra já vendida,
 * ou venda cujo dinheiro já foi gasto).
 */
function excluirAporteRepository(PDO $conexao, $idAporte, $idUsuario)
{
    try {
        $conexao->beginTransaction();

        $stmtAporte = $conexao->prepare("
            SELECT a.id_aporte, a.id_carteira, a.id_ativo, a.valor_aporte,
                   a.tipo_aporte::text AS tipo_aporte,
                   c.tipo_carteira::text AS tipo_carteira
            FROM aporte a
            INNER JOIN carteira c ON c.id_carteira = a.id_carteira
            WHERE a.id_aporte = ?
              AND c.id_usuario = ?
            FOR UPDATE OF a, c
        ");
        $stmtAporte->execute([$idAporte, $idUsuario]);
        $aporte = $stmtAporte->fetch(PDO::FETCH_ASSOC);

        if (!$aporte) {
            $conexao->rollBack();
            return false;
        }

        $idCarteira = (int) $aporte["id_carteira"];
        $idAtivo = (int) $aporte["id_ativo"];

        $restantes = operacoesDoAtivoNaCarteira($conexao, $idCarteira, $idAtivo, (int) $aporte["id_aporte"]);

        if (calcularPosicaoPorOperacoes($restantes) === null) {
            throw new AporteValidacaoException(
                "Esta compra já foi vendida (total ou parcialmente). Exclua primeiro as vendas deste ativo."
            );
        }

        estornarSaldoDoAporte(
            $conexao,
            (int) $idUsuario,
            $idCarteira,
            $aporte["tipo_carteira"],
            $aporte["tipo_aporte"],
            (string) $aporte["valor_aporte"]
        );

        $conexao->prepare("DELETE FROM aporte WHERE id_aporte = ?")->execute([$idAporte]);

        recalcularPosicaoAposExclusao($conexao, $idCarteira, $idAtivo);

        $conexao->commit();

        return true;
    } catch (Throwable $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }

        throw $erro;
    }
}

/** Devolve ao saldo o valor de uma compra excluída, ou retira o valor de uma venda/dividendo excluído. */
function estornarSaldoDoAporte(PDO $conexao, int $idUsuario, int $idCarteira, string $tipoCarteira, string $tipoAporte, string $valor): void
{
    $compra = $tipoAporte === "Compra";

    if ($tipoCarteira === "Simulada") {
        $sql = $compra
            ? "UPDATE carteira SET saldo_livre_carteira = saldo_livre_carteira + CAST(:valor AS NUMERIC)
               WHERE id_carteira = :alvo"
            : "UPDATE carteira SET saldo_livre_carteira = saldo_livre_carteira - CAST(:valor AS NUMERIC)
               WHERE id_carteira = :alvo AND saldo_livre_carteira >= CAST(:valor AS NUMERIC)";
        $alvo = $idCarteira;
    } else {
        $sql = $compra
            ? "UPDATE usuario SET saldo = saldo + CAST(:valor AS NUMERIC) WHERE id_usuario = :alvo"
            : "UPDATE usuario SET saldo = saldo - CAST(:valor AS NUMERIC)
               WHERE id_usuario = :alvo AND saldo >= CAST(:valor AS NUMERIC)";
        $alvo = $idUsuario;
    }

    $stmt = $conexao->prepare($sql);
    $stmt->execute([":valor" => $valor, ":alvo" => $alvo]);

    if ($stmt->rowCount() === 0) {
        throw new AporteValidacaoException(
            "Não há saldo suficiente para desfazer esta operação: o valor recebido já foi usado em outras compras."
        );
    }
}

/** Operações de um ativo na carteira em ordem cronológica (opcionalmente ignorando um aporte). */
function operacoesDoAtivoNaCarteira(PDO $conexao, int $idCarteira, int $idAtivo, ?int $ignorarAporte = null): array
{
    $stmt = $conexao->prepare("
        SELECT tipo_aporte::text AS tipo_aporte,
               COALESCE(quantidade_aporte, 0) AS quantidade_aporte,
               valor_aporte
        FROM aporte
        WHERE id_carteira = :carteira
          AND id_ativo = :ativo
          AND id_aporte <> :ignorar
          AND status_aporte = 'Concluída'
        ORDER BY data_aporte, id_aporte
    ");
    $stmt->execute([":carteira" => $idCarteira, ":ativo" => $idAtivo, ":ignorar" => $ignorarAporte ?? 0]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Refaz a posição a partir das operações: compras somam quantidade e custo (preço médio
 * ponderado) e vendas tiram quantidade pelo preço médio. Devolve null se alguma venda
 * ficar maior que a quantidade disponível naquele momento.
 */
function calcularPosicaoPorOperacoes(array $operacoes): ?array
{
    $quantidade = 0.0;
    $custo = 0.0;

    foreach ($operacoes as $operacao) {
        $qtd = (float) $operacao["quantidade_aporte"];

        if ($operacao["tipo_aporte"] === "Compra") {
            $quantidade += $qtd;
            $custo += (float) $operacao["valor_aporte"];
        } elseif ($operacao["tipo_aporte"] === "Venda") {
            if ($qtd > $quantidade + 1e-8) {
                return null;
            }

            $precoMedio = $quantidade > 0 ? $custo / $quantidade : 0.0;
            $quantidade -= $qtd;
            $custo -= $qtd * $precoMedio;
        }
    }

    $quantidade = max(0.0, round($quantidade, 8));

    return [
        "quantidade" => $quantidade,
        "preco_medio" => $quantidade > 0 ? max(0.0, $custo) / $quantidade : 0.0,
    ];
}

function recalcularPosicaoAposExclusao(PDO $conexao, $idCarteira, $idAtivo)
{
    $posicao = calcularPosicaoPorOperacoes(operacoesDoAtivoNaCarteira($conexao, (int) $idCarteira, (int) $idAtivo));

    if ($posicao === null || $posicao["quantidade"] <= 0) {
        $conexao->prepare("DELETE FROM item_investimento WHERE id_carteira = ? AND id_ativo = ?")
            ->execute([$idCarteira, $idAtivo]);
        return;
    }

    $stmt = $conexao->prepare("
        INSERT INTO item_investimento (id_carteira, id_ativo, quantidade_item, preco_medio_item, atualizado_em)
        VALUES (?, ?, ?, ?, NOW())
        ON CONFLICT (id_carteira, id_ativo)
        DO UPDATE SET
            quantidade_item = EXCLUDED.quantidade_item,
            preco_medio_item = EXCLUDED.preco_medio_item,
            atualizado_em = NOW()
    ");
    $stmt->execute([$idCarteira, $idAtivo, $posicao["quantidade"], round($posicao["preco_medio"], 4)]);
}

function listarAportesDestaque(PDO $conexao, $idUsuario, $Choise)
{
    // Variação do dia = último fechamento de dia útil vs. fechamento anterior
    // (sábado e domingo repetem o fechamento de sexta, então são ignorados)
    $base = "
        WITH ranked AS (
            SELECT id_ativo, preco_fechamento_cotacao AS preco,
                   ROW_NUMBER() OVER (PARTITION BY id_ativo ORDER BY data_cotacao DESC) AS rn
            FROM Cotacao_Ativo
            WHERE EXTRACT(DOW FROM data_cotacao) BETWEEN 1 AND 5
        ),
        variacao AS (
            SELECT a.id_ativo, a.preco AS preco_atual,
                   CASE WHEN p.preco > 0 THEN (a.preco - p.preco) / p.preco * 100 ELSE 0 END AS variacao
            FROM ranked a
            LEFT JOIN ranked p ON p.id_ativo = a.id_ativo AND p.rn = 2
            WHERE a.rn = 1
        )
        SELECT
            MIN(ii.id_carteira) AS id_carteira,
            atv.simbolo_ativo   AS ativo_aporte,
            atv.nome_ativo      AS name_ativo,
            atv.categoria_ativo AS categoria_ativo,
            SUM(ii.quantidade_item) AS quantidade_aporte,
            SUM(ii.quantidade_item * COALESCE(v.preco_atual, ii.preco_medio_item)) AS valor_aporte,
            COALESCE(v.variacao, 0) AS rendimento_percentual,
            'Posicao' AS tipo_aporte,
            ''        AS recorrencia_aporte
        FROM Item_Investimento ii
        JOIN Carteira c ON c.id_carteira = ii.id_carteira
        JOIN Ativo atv  ON atv.id_ativo = ii.id_ativo
        LEFT JOIN variacao v ON v.id_ativo = ii.id_ativo
        WHERE c.id_usuario = ?
          AND c.deleted_at IS NULL
          AND ii.quantidade_item > 0
        GROUP BY atv.id_ativo, atv.simbolo_ativo, atv.nome_ativo, atv.categoria_ativo, v.variacao
    ";

    switch ($Choise) {
        case 'Mais caros':
            $sql = $base . " ORDER BY valor_aporte DESC LIMIT 4";
            break;
        case 'Mais lucrativos':
            $sql = $base . " HAVING COALESCE(v.variacao, 0) > 0 ORDER BY rendimento_percentual DESC LIMIT 4";
            break;
        case 'Maiores perdas':
            $sql = $base . " HAVING COALESCE(v.variacao, 0) < 0 ORDER BY rendimento_percentual ASC LIMIT 4";
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

function listarTitulosRendaFixa(PDO $conexao){
    $sql = "
        SELECT
            id_titulo,
            nome_titulo,
            tipo_titulo,
            rentabilidade,
            indexador,
            aplicacao_minima,
            vencimento,
            liquidez,
            taxa_administracao,
            possui_garantia_fgc,
            descricao
        FROM titulos_renda_fixa
        WHERE ativo = TRUE
        ORDER BY id_titulo DESC
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}