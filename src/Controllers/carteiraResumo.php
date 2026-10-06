<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/cors.php";
require_once __DIR__ . "/../database/conexao.php";
require_once __DIR__ . "/../Authentication/Authentication.php";
require_once __DIR__ . "/../service/aporteRecorrenteService.php";   // carteiraPertenceAoUsuario()

$idUsuario = autenticar();

$idCarteira = $_GET["id_carteira"] ?? 1;

try {

    if ($idCarteira !== null && $idCarteira !== "" && !carteiraPertenceAoUsuario($conexao, $idCarteira, $idUsuario)) {
        http_response_code(403);
        echo json_encode(["sucesso" => false, "mensagem" => "A carteira não pertence ao usuário."]);
        exit;
    }

    $filtroCarteira = ($idCarteira !== null && $idCarteira !== "") ? " AND i.id_carteira = :carteira" : "";

    $sql = "
        SELECT a.id_ativo, a.simbolo_ativo, a.nome_ativo, a.categoria_ativo,
               SUM(i.quantidade_item) AS quantidade,
               SUM(i.quantidade_item * i.preco_medio_item) / NULLIF(SUM(i.quantidade_item), 0) AS preco_medio,
               cot.preco_fechamento_cotacao AS preco_atual,
               cot.data_cotacao
        FROM item_investimento i
        JOIN carteira ca ON ca.id_carteira = i.id_carteira
        JOIN ativo a ON a.id_ativo = i.id_ativo
        LEFT JOIN LATERAL (
            SELECT data_cotacao, preco_fechamento_cotacao
            FROM cotacao_ativo
            WHERE id_ativo = a.id_ativo
            ORDER BY data_cotacao DESC
            LIMIT 1
        ) cot ON TRUE
        WHERE ca.id_usuario = :usuario
          AND i.quantidade_item > 0
          $filtroCarteira
        GROUP BY a.id_ativo, a.simbolo_ativo, a.nome_ativo, a.categoria_ativo,
                 cot.preco_fechamento_cotacao, cot.data_cotacao
        ORDER BY a.nome_ativo
    ";

    $params = [":usuario" => $idUsuario];

    if ($filtroCarteira !== "") {
        $params[":carteira"] = $idCarteira;
    }

    $stmt = $conexao->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalInvestido = 0.0;
    $valorMercado = 0.0;
    $porCategoria = [];
    $posicoes = [];
    $ultimaCotacao = null;

    foreach ($linhas as $linha) {

        $quantidade = (float) $linha["quantidade"];
        $precoMedio = (float) $linha["preco_medio"];
        $investido = $quantidade * $precoMedio;

        $semCotacao = $linha["preco_atual"] === null;
        $precoAtual = $semCotacao ? null : (float) $linha["preco_atual"];
        // sem cotação ainda: usa o custo como valor (não inventa lucro nem prejuízo)
        $valorAtual = $semCotacao ? $investido : $quantidade * $precoAtual;

        $retornoPct = $investido > 0 ? (($valorAtual - $investido) / $investido) * 100 : 0.0;

        $totalInvestido += $investido;
        $valorMercado += $valorAtual;
        $porCategoria[$linha["categoria_ativo"]] = ($porCategoria[$linha["categoria_ativo"]] ?? 0.0) + $valorAtual;

        if (!$semCotacao && ($ultimaCotacao === null || $linha["data_cotacao"] > $ultimaCotacao)) {
            $ultimaCotacao = $linha["data_cotacao"];
        }

        $posicoes[] = [
            "id_ativo" => (int) $linha["id_ativo"],
            "simbolo_ativo" => $linha["simbolo_ativo"],
            "nome_ativo" => $linha["nome_ativo"],
            "categoria_ativo" => $linha["categoria_ativo"],
            "quantidade" => $quantidade,
            "preco_medio" => round($precoMedio, 4),
            "preco_atual" => $precoAtual === null ? null : round($precoAtual, 4),
            "data_cotacao" => $linha["data_cotacao"],
            "sem_cotacao" => $semCotacao,
            "valor_investido" => round($investido, 2),
            "valor_atual" => round($valorAtual, 2),
            "retorno_percentual" => round($retornoPct, 2)
        ];
    }

    $distribuicao = [];

    foreach ($porCategoria as $categoria => $valor) {
        $distribuicao[] = [
            "categoria_ativo" => $categoria,
            "valor" => round($valor, 2),
            "percentual" => $valorMercado > 0 ? round(($valor / $valorMercado) * 100, 1) : 0.0
        ];
    }

    usort($distribuicao, fn($x, $y) => $y["valor"] <=> $x["valor"]);

    // saldo livre (dinheiro na carteira que não está investido)
    $sqlSaldo = "SELECT COALESCE(SUM(saldo_livre_carteira), 0) FROM carteira WHERE id_usuario = :usuario";
    $paramsSaldo = [":usuario" => $idUsuario];

    if ($idCarteira !== null && $idCarteira !== "") {
        $sqlSaldo .= " AND id_carteira = :carteira";
        $paramsSaldo[":carteira"] = $idCarteira;
    }

    $stmtSaldo = $conexao->prepare($sqlSaldo);
    $stmtSaldo->execute($paramsSaldo);
    $saldoLivre = (float) $stmtSaldo->fetchColumn();

    $rendimento = $valorMercado - $totalInvestido;

    echo json_encode([
        "sucesso" => true,
        "resumo" => [
            "patrimonio_total" => round($valorMercado + $saldoLivre, 2),
            "total_investido" => round($totalInvestido, 2),
            "valor_mercado" => round($valorMercado, 2),
            "saldo_livre" => round($saldoLivre, 2),
            "rendimento" => round($rendimento, 2),
            "rendimento_percentual" => $totalInvestido > 0 ? round(($rendimento / $totalInvestido) * 100, 2) : 0.0,
            "cotacao_atualizada_em" => $ultimaCotacao
        ],
        "posicoes" => $posicoes,
        "distribuicao" => $distribuicao
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $erro) {

    error_log("carteiraResumo.php: " . $erro->getMessage());
    http_response_code(500);
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao montar o resumo da carteira."]);
}
