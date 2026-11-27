<?php

// Consultas (somente leitura) usadas no cálculo do patrimônio das carteiras.

function buscarSaldoGeralUsuario(PDO $conexao, int $idUsuario): float
{
    $stmt = $conexao->prepare("SELECT saldo FROM usuario WHERE id_usuario = :id");
    $stmt->execute([":id" => $idUsuario]);

    return (float) ($stmt->fetchColumn() ?: 0);
}

function buscarCarteirasDetalhadas(PDO $conexao, int $idUsuario, ?int $idCarteira = null): array
{
    $sql = "
        SELECT
            c.id_carteira,
            c.nome_carteira,
            c.tipo_carteira::text AS tipo_carteira,
            c.estilo_carteira::text AS estilo_carteira,
            c.meta_carteira,
            c.saldo_livre_carteira,
            c.capital_simulado_carteira,
            c.meta_atingida_em,
            c.criado_em,
            COALESCE(SUM(CASE WHEN a.tipo_aporte = 'Compra' THEN a.valor_aporte END), 0) AS total_aportado
        FROM carteira c
        LEFT JOIN aporte a ON a.id_carteira = c.id_carteira
        WHERE c.id_usuario = :usuario
          AND c.deleted_at IS NULL
    ";

    $parametros = [":usuario" => $idUsuario];

    if ($idCarteira !== null) {
        $sql .= " AND c.id_carteira = :carteira";
        $parametros[":carteira"] = $idCarteira;
    }

    $sql .= " GROUP BY c.id_carteira ORDER BY c.id_carteira DESC";

    $stmt = $conexao->prepare($sql);
    $stmt->execute($parametros);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Posições abertas, já com o último fechamento gravado pelo cron (fallback de preço).
function buscarPosicoesAbertas(PDO $conexao, int $idUsuario, ?int $idCarteira = null): array
{
    $sql = "
        SELECT
            ii.id_carteira,
            ii.id_ativo,
            a.simbolo_ativo,
            a.nome_ativo,
            a.categoria_ativo::text AS categoria_ativo,
            ii.quantidade_item,
            ii.preco_medio_item,
            cot.preco_fechamento_cotacao AS ultimo_fechamento,
            cot.data_cotacao AS data_ultimo_fechamento
        FROM item_investimento ii
        INNER JOIN carteira c ON c.id_carteira = ii.id_carteira
        INNER JOIN ativo a ON a.id_ativo = ii.id_ativo
        LEFT JOIN LATERAL (
            SELECT ca.preco_fechamento_cotacao, ca.data_cotacao
            FROM cotacao_ativo ca
            WHERE ca.id_ativo = ii.id_ativo
            ORDER BY ca.data_cotacao DESC
            LIMIT 1
        ) cot ON TRUE
        WHERE c.id_usuario = :usuario
          AND c.deleted_at IS NULL
          AND ii.quantidade_item > 0
    ";

    $parametros = [":usuario" => $idUsuario];

    if ($idCarteira !== null) {
        $sql .= " AND ii.id_carteira = :carteira";
        $parametros[":carteira"] = $idCarteira;
    }

    $sql .= " ORDER BY ii.id_carteira, a.simbolo_ativo";

    $stmt = $conexao->prepare($sql);
    $stmt->execute($parametros);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Operações em ordem cronológica, para calcular o resultado já realizado (vendas e dividendos).
function buscarOperacoesCronologicas(PDO $conexao, int $idUsuario, ?int $idCarteira = null): array
{
    $sql = "
        SELECT
            ap.id_carteira,
            ap.id_ativo,
            ap.tipo_aporte::text AS tipo_aporte,
            COALESCE(ap.quantidade_aporte, 0) AS quantidade_aporte,
            ap.valor_aporte
        FROM aporte ap
        INNER JOIN carteira c ON c.id_carteira = ap.id_carteira
        WHERE c.id_usuario = :usuario
          AND c.deleted_at IS NULL
          AND ap.status_aporte = 'Concluída'
    ";

    $parametros = [":usuario" => $idUsuario];

    if ($idCarteira !== null) {
        $sql .= " AND ap.id_carteira = :carteira";
        $parametros[":carteira"] = $idCarteira;
    }

    $sql .= " ORDER BY ap.data_aporte, ap.id_aporte";

    $stmt = $conexao->prepare($sql);
    $stmt->execute($parametros);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Aplicações em renda fixa com as condições contratadas (para estimar o rendimento).
function buscarAplicacoesRendaFixa(PDO $conexao, int $idUsuario, ?int $idCarteira = null): array
{
    if (!tabelasDetalheAporteExistem($conexao)) {
        return [];
    }

    $sql = "
        SELECT
            ap.id_carteira,
            ap.id_ativo,
            ap.valor_aporte,
            to_char(ap.data_aporte, 'YYYY-MM-DD') AS data_aporte,
            fi.contracted_rate AS taxa,
            fi.rate_index AS indexador,
            to_char(fi.maturity_date, 'YYYY-MM-DD') AS vencimento
        FROM aporte ap
        INNER JOIN carteira c ON c.id_carteira = ap.id_carteira
        INNER JOIN fixed_income_contribution_details fi ON fi.contribution_id = ap.id_aporte
        WHERE c.id_usuario = :usuario
          AND c.deleted_at IS NULL
          AND ap.tipo_aporte = 'Compra'
          AND ap.status_aporte = 'Concluída'
    ";

    $parametros = [":usuario" => $idUsuario];

    if ($idCarteira !== null) {
        $sql .= " AND ap.id_carteira = :carteira";
        $parametros[":carteira"] = $idCarteira;
    }

    $stmt = $conexao->prepare($sql);
    $stmt->execute($parametros);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
