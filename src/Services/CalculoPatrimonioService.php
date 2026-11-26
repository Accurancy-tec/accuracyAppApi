<?php

// Regras de cálculo do patrimônio. Não acessam banco nem rede: recebem os dados
// prontos e devolvem os números, o que permite testá-las isoladamente.

const CATEGORIAS_COM_COTACAO_DE_MERCADO = ["Ações", "FIIs", "Internacional"];

/**
 * Taxa anual efetiva (em fração, 0.12 = 12% a.a.) de uma aplicação de renda fixa.
 * Pós-fixados usam o indicador atual; sem ele devolve null (o valor fica no custo).
 */
function taxaAnualRendaFixa(string $indexador, float $taxaContratada, array $indicadores): ?float
{
    switch (strtoupper(trim($indexador))) {
        case "PRE":
        case "PREFIXADO":
            return $taxaContratada / 100;

        case "CDI":
            return isset($indicadores["cdi"])
                ? ($indicadores["cdi"] / 100) * ($taxaContratada / 100)
                : null;

        case "SELIC":
            return isset($indicadores["selic"])
                ? ($indicadores["selic"] / 100) * ($taxaContratada / 100)
                : null;

        case "IPCA":
            return isset($indicadores["ipca"])
                ? (1 + $indicadores["ipca"] / 100) * (1 + $taxaContratada / 100) - 1
                : null;

        default:
            return null;
    }
}

/**
 * Valor estimado de uma aplicação em renda fixa na data informada: juros compostos
 * por dias corridos (base 365), parando de render no vencimento.
 */
function valorAtualRendaFixa(
    float $valorAplicado,
    string $dataAplicacao,
    ?string $vencimento,
    string $indexador,
    float $taxaContratada,
    array $indicadores,
    string $hoje
): float {
    $taxaAnual = taxaAnualRendaFixa($indexador, $taxaContratada, $indicadores);

    if ($taxaAnual === null || $valorAplicado <= 0) {
        return $valorAplicado;
    }

    $fim = ($vencimento !== null && $vencimento !== "" && $vencimento < $hoje) ? $vencimento : $hoje;
    $dias = (int) floor((strtotime($fim) - strtotime($dataAplicacao)) / 86400);

    if ($dias <= 0) {
        return $valorAplicado;
    }

    return $valorAplicado * pow(1 + $taxaAnual, $dias / 365);
}

/**
 * Resultado já realizado em cada carteira, refazendo as operações em ordem:
 * lucro/prejuízo das vendas (comparado ao preço médio da época) e dividendos recebidos.
 *
 * @param array $operacoes linhas com id_carteira, id_ativo, tipo_aporte, quantidade_aporte, valor_aporte
 * @return array [id_carteira => ['vendas' => float, 'dividendos' => float]]
 */
function calcularResultadoRealizado(array $operacoes): array
{
    $posicoes = [];
    $resultado = [];

    foreach ($operacoes as $operacao) {
        $idCarteira = (int) $operacao["id_carteira"];
        $chave = $idCarteira . ":" . (int) $operacao["id_ativo"];
        $quantidade = (float) $operacao["quantidade_aporte"];
        $valor = (float) $operacao["valor_aporte"];

        $resultado[$idCarteira] ??= ["vendas" => 0.0, "dividendos" => 0.0];
        $posicoes[$chave] ??= ["quantidade" => 0.0, "custo" => 0.0];
        $posicao = &$posicoes[$chave];

        switch ($operacao["tipo_aporte"]) {
            case "Compra":
                $posicao["quantidade"] += $quantidade;
                $posicao["custo"] += $valor;
                break;

            case "Venda":
                $quantidadeAtual = $posicao["quantidade"];
                $precoMedio = $quantidadeAtual > 0 ? $posicao["custo"] / $quantidadeAtual : 0.0;
                $custoVendido = min($quantidade, $quantidadeAtual) * $precoMedio;

                $resultado[$idCarteira]["vendas"] += $valor - $custoVendido;
                $posicao["quantidade"] = max(0.0, $quantidadeAtual - $quantidade);
                $posicao["custo"] = max(0.0, $posicao["custo"] - $custoVendido);
                break;

            case "Dividendo":
                $resultado[$idCarteira]["dividendos"] += $valor;
                break;
        }

        unset($posicao);
    }

    return $resultado;
}

/**
 * Consolida uma carteira a partir das posições já precificadas (preco_atual preenchido).
 *
 * Real     -> patrimônio = valor de mercado das posições (o dinheiro livre é o saldo geral)
 * Simulada -> patrimônio = posições + saldo livre da própria simulação; a rentabilidade
 *             da simulação compara esse patrimônio com o capital fictício colocado nela.
 */
function consolidarCarteira(array $carteira, array $posicoes, array $realizado = []): array
{
    $simulada = ($carteira["tipo_carteira"] ?? "") === "Simulada";
    $ativos = [];
    $custo = 0.0;
    $mercado = 0.0;
    $todosComCotacao = true;

    foreach ($posicoes as $posicao) {
        $quantidade = (float) $posicao["quantidade_item"];
        $precoMedio = (float) $posicao["preco_medio_item"];
        $precoAtual = (float) $posicao["preco_atual"];
        $custoAtivo = $quantidade * $precoMedio;
        $valorAtivo = $quantidade * $precoAtual;

        $custo += $custoAtivo;
        $mercado += $valorAtivo;

        if (($posicao["origem_preco"] ?? "custo") === "custo") {
            $todosComCotacao = false;
        }

        $ativos[] = [
            "id_ativo" => (int) $posicao["id_ativo"],
            "simbolo_ativo" => $posicao["simbolo_ativo"],
            "nome_ativo" => $posicao["nome_ativo"],
            "categoria_ativo" => $posicao["categoria_ativo"],
            "quantidade" => $quantidade,
            "preco_medio" => round($precoMedio, 4),
            "preco_atual" => round($precoAtual, 4),
            "origem_preco" => $posicao["origem_preco"] ?? "custo",
            "valor_investido" => round($custoAtivo, 2),
            "valor_atual" => round($valorAtivo, 2),
            "lucro_prejuizo" => round($valorAtivo - $custoAtivo, 2),
            "rentabilidade_percentual" => $custoAtivo > 0
                ? round(($valorAtivo - $custoAtivo) / $custoAtivo * 100, 2)
                : 0.0,
        ];
    }

    foreach ($ativos as &$ativo) {
        $ativo["peso_percentual"] = $mercado > 0 ? round($ativo["valor_atual"] / $mercado * 100, 2) : 0.0;
    }
    unset($ativo);

    $saldoLivre = $simulada ? (float) $carteira["saldo_livre_carteira"] : 0.0;
    $patrimonio = $mercado + $saldoLivre;
    $lucro = $mercado - $custo;
    $meta = (float) ($carteira["meta_carteira"] ?? 0);
    $vendas = (float) ($realizado["vendas"] ?? 0);
    $dividendos = (float) ($realizado["dividendos"] ?? 0);

    $consolidada = [
        "id_carteira" => (int) $carteira["id_carteira"],
        "nome_carteira" => $carteira["nome_carteira"],
        "tipo_carteira" => $carteira["tipo_carteira"],
        "estilo_carteira" => $carteira["estilo_carteira"],
        "meta_carteira" => round($meta, 2),
        "total_aportado" => round((float) ($carteira["total_aportado"] ?? 0), 2),
        "saldo_livre_carteira" => round($saldoLivre, 2),
        "total_investido" => round($custo, 2),
        "valor_mercado" => round($mercado, 2),
        "patrimonio_atual" => round($patrimonio, 2),
        "lucro_prejuizo" => round($lucro, 2),
        "rentabilidade_percentual" => $custo > 0 ? round($lucro / $custo * 100, 2) : 0.0,
        "resultado_vendas" => round($vendas, 2),
        "dividendos_recebidos" => round($dividendos, 2),
        "progresso_meta" => $meta > 0 ? round(min(100, $patrimonio / $meta * 100), 2) : null,
        "meta_atingida" => $meta > 0 && $patrimonio >= $meta,
        "quantidade_ativos" => count($ativos),
        "cotacoes_atualizadas" => $todosComCotacao,
        "ativos" => $ativos,
    ];

    if ($simulada) {
        $capital = (float) ($carteira["capital_simulado_carteira"] ?? 0);
        $resultadoSimulacao = $patrimonio - $capital;

        $consolidada["capital_simulado"] = round($capital, 2);
        $consolidada["resultado_simulacao"] = round($resultadoSimulacao, 2);
        $consolidada["rentabilidade_simulacao"] = $capital > 0
            ? round($resultadoSimulacao / $capital * 100, 2)
            : 0.0;
    }

    return $consolidada;
}

/** Soma o valor de mercado por categoria de ativo e devolve a participação de cada uma. */
function distribuicaoPorCategoria(array $carteiras): array
{
    $porCategoria = [];

    foreach ($carteiras as $carteira) {
        foreach ($carteira["ativos"] as $ativo) {
            $categoria = $ativo["categoria_ativo"];
            $porCategoria[$categoria] = ($porCategoria[$categoria] ?? 0) + $ativo["valor_atual"];
        }
    }

    arsort($porCategoria);
    $total = array_sum($porCategoria);
    $distribuicao = [];

    foreach ($porCategoria as $categoria => $valor) {
        if ($valor <= 0) {
            continue;
        }

        $distribuicao[] = [
            "categoria" => $categoria,
            "valor" => round($valor, 2),
            "percentual" => $total > 0 ? round($valor / $total * 100, 2) : 0.0,
        ];
    }

    return $distribuicao;
}

/** Totais das carteiras reais (somadas ao saldo geral) e das simuladas (separadas). */
function resumirCarteiras(array $carteiras, float $saldoGeral): array
{
    $real = ["valor_mercado" => 0.0, "total_investido" => 0.0, "resultado_vendas" => 0.0, "dividendos" => 0.0, "quantidade" => 0];
    $simulado = ["patrimonio" => 0.0, "capital" => 0.0, "saldo_livre" => 0.0, "quantidade" => 0];

    foreach ($carteiras as $carteira) {
        if ($carteira["tipo_carteira"] === "Simulada") {
            $simulado["patrimonio"] += $carteira["patrimonio_atual"];
            $simulado["capital"] += $carteira["capital_simulado"] ?? 0;
            $simulado["saldo_livre"] += $carteira["saldo_livre_carteira"];
            $simulado["quantidade"]++;
            continue;
        }

        $real["valor_mercado"] += $carteira["valor_mercado"];
        $real["total_investido"] += $carteira["total_investido"];
        $real["resultado_vendas"] += $carteira["resultado_vendas"];
        $real["dividendos"] += $carteira["dividendos_recebidos"];
        $real["quantidade"]++;
    }

    $lucroReal = $real["valor_mercado"] - $real["total_investido"];
    $resultadoSimulado = $simulado["patrimonio"] - $simulado["capital"];

    return [
        "saldo_geral" => round($saldoGeral, 2),
        "real" => [
            "patrimonio_total" => round($saldoGeral + $real["valor_mercado"], 2),
            "valor_mercado" => round($real["valor_mercado"], 2),
            "total_investido" => round($real["total_investido"], 2),
            "lucro_prejuizo" => round($lucroReal, 2),
            "rentabilidade_percentual" => $real["total_investido"] > 0
                ? round($lucroReal / $real["total_investido"] * 100, 2)
                : 0.0,
            "resultado_vendas" => round($real["resultado_vendas"], 2),
            "dividendos_recebidos" => round($real["dividendos"], 2),
            "quantidade_carteiras" => $real["quantidade"],
        ],
        "simulado" => [
            "patrimonio_total" => round($simulado["patrimonio"], 2),
            "capital_simulado" => round($simulado["capital"], 2),
            "saldo_disponivel" => round($simulado["saldo_livre"], 2),
            "resultado" => round($resultadoSimulado, 2),
            "rentabilidade_percentual" => $simulado["capital"] > 0
                ? round($resultadoSimulado / $simulado["capital"] * 100, 2)
                : 0.0,
            "quantidade_carteiras" => $simulado["quantidade"],
        ],
    ];
}
