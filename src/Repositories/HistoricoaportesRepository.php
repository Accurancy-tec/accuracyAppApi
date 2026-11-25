<?php

require_once __DIR__ . '/DetalheAporteRepository.php';

/**
 * Monta o WHERE do histórico a partir dos filtros já validados no controller.
 * Filtros aceitos: id_carteira, tipo, inicio, fim (YYYY-MM-DD) e busca (código ou nome do ativo).
 */
function filtrosHistoricoSql(array $filtros, array &$parametros): string
{
    $condicoes = ["c.id_usuario = :usuario"];

    if (!empty($filtros["id_carteira"])) {
        $condicoes[] = "a.id_carteira = :carteira";
        $parametros[":carteira"] = $filtros["id_carteira"];
    }

    if (!empty($filtros["tipo"])) {
        $condicoes[] = "a.tipo_aporte = CAST(:tipo AS tipo_aporte_enum)";
        $parametros[":tipo"] = $filtros["tipo"];
    }

    if (!empty($filtros["inicio"])) {
        $condicoes[] = "a.data_aporte >= CAST(:inicio AS date)";
        $parametros[":inicio"] = $filtros["inicio"];
    }

    if (!empty($filtros["fim"])) {
        $condicoes[] = "a.data_aporte <= CAST(:fim AS date)";
        $parametros[":fim"] = $filtros["fim"];
    }

    if (!empty($filtros["busca"])) {
        $condicoes[] = "(ati.simbolo_ativo ILIKE :busca OR ati.nome_ativo ILIKE :busca)";
        $parametros[":busca"] = "%" . addcslashes($filtros["busca"], "%_\\") . "%";
    }

    return implode(" AND ", $condicoes);
}

// Aportes do usuário (todos ou filtrados), já com data, carteira e detalhes da operação.
function listarHistoricoAportesUsuario(PDO $conexao, $idUsuario, array $filtros = [], ?int $limite = null, int $deslocamento = 0)
{
    $parametros = [":usuario" => $idUsuario];
    $where = filtrosHistoricoSql($filtros, $parametros);

    $sql = "
        SELECT
            a.id_aporte,
            a.id_carteira,
            c.nome_carteira,
            c.tipo_carteira::text AS tipo_carteira,
            ati.simbolo_ativo AS ativo_aporte,
            ati.nome_ativo AS name_ativo,
            ati.categoria_ativo,
            a.quantidade_aporte,
            a.valor_aporte,
            a.tipo_aporte,
            a.recorrencia_aporte,
            a.observacao_aporte,
            to_char(a.data_aporte, 'YYYY-MM-DD') AS data_aporte
        FROM aporte a
        INNER JOIN carteira c ON c.id_carteira = a.id_carteira
        INNER JOIN ativo ati ON ati.id_ativo = a.id_ativo
        WHERE $where
        ORDER BY a.data_aporte DESC, a.id_aporte DESC
    ";

    if ($limite !== null) {
        $sql .= " LIMIT " . max(1, $limite) . " OFFSET " . max(0, $deslocamento);
    }

    $stmt = $conexao->prepare($sql);
    $stmt->execute($parametros);

    return anexarDetalhesAportes($conexao, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Quantidade de aportes e soma por tipo, respeitando os mesmos filtros da listagem. */
function resumirHistoricoAportes(PDO $conexao, $idUsuario, array $filtros = []): array
{
    $parametros = [":usuario" => $idUsuario];
    $where = filtrosHistoricoSql($filtros, $parametros);

    $stmt = $conexao->prepare("
        SELECT
            COUNT(*) AS quantidade,
            COALESCE(SUM(CASE WHEN a.tipo_aporte = 'Compra' THEN a.valor_aporte END), 0) AS compras,
            COALESCE(SUM(CASE WHEN a.tipo_aporte = 'Venda' THEN a.valor_aporte END), 0) AS vendas,
            COALESCE(SUM(CASE WHEN a.tipo_aporte = 'Dividendo' THEN a.valor_aporte END), 0) AS dividendos
        FROM aporte a
        INNER JOIN carteira c ON c.id_carteira = a.id_carteira
        INNER JOIN ativo ati ON ati.id_ativo = a.id_ativo
        WHERE $where
    ");
    $stmt->execute($parametros);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        "quantidade" => (int) ($linha["quantidade"] ?? 0),
        "total_compras" => round((float) ($linha["compras"] ?? 0), 2),
        "total_vendas" => round((float) ($linha["vendas"] ?? 0), 2),
        "total_dividendos" => round((float) ($linha["dividendos"] ?? 0), 2),
    ];
}

// Depósitos fictícios (saldo geral e saldo de simulações), do mais recente ao mais antigo.
function listarDepositosUsuario(PDO $conexao, $idUsuario, array $filtros = []): array
{
    $condicoes = ["t.id_usuario = :usuario", "t.tipo_transacao = 'Deposito'"];
    $parametros = [":usuario" => $idUsuario];

    if (!empty($filtros["id_carteira"])) {
        $condicoes[] = "t.id_carteira = :carteira";
        $parametros[":carteira"] = $filtros["id_carteira"];
    }

    if (!empty($filtros["inicio"])) {
        $condicoes[] = "t.data_transacao >= CAST(:inicio AS date)";
        $parametros[":inicio"] = $filtros["inicio"];
    }

    if (!empty($filtros["fim"])) {
        $condicoes[] = "t.data_transacao < CAST(:fim AS date) + 1";
        $parametros[":fim"] = $filtros["fim"];
    }

    $stmt = $conexao->prepare("
        SELECT
            t.id_transacao,
            t.id_carteira,
            c.nome_carteira,
            t.valor,
            t.descricao,
            to_char(t.data_transacao, 'YYYY-MM-DD') AS data
        FROM transacao_saldo t
        LEFT JOIN carteira c ON c.id_carteira = t.id_carteira
        WHERE " . implode(" AND ", $condicoes) . "
        ORDER BY t.data_transacao DESC, t.id_transacao DESC
        LIMIT 500
    ");
    $stmt->execute($parametros);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
