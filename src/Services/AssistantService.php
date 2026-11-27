<?php

// Accuracy's own assistant: a rule-based expert system that reads the report numbers, scores
// diversification and risk, writes a short analysis and suggests course trails. It runs inside
// the API (no external service, no key, no usage limit) and never says what to buy or sell.

require_once __DIR__ . "/FormatService.php";

const ASSISTANT_ENGINE = "Assistente Accuracy";

// 1 = most stable, 5 = most volatile.
const ASSISTANT_CATEGORY_RISK = [
    "Renda Fixa" => 1,
    "FIIs" => 3,
    "Ações" => 4,
    "Internacional" => 4,
    "Cripto" => 5,
    "Futuros" => 5,
];

// Course trail (title in the database) that explains each category.
const ASSISTANT_CATEGORY_TRAIL = [
    "Renda Fixa" => "Renda Fixa",
    "FIIs" => "FIIs",
    "Ações" => "Ações",
    "Cripto" => "Cripto",
];

/** Picks one of several wordings; the seed changes once a day, not on every reload. */
function assistantVary(array $options, int $seed): string
{
    return $options[$seed % count($options)];
}

/** Asset and category weights. HHI = sum of the squared shares (1 = everything in one place). */
function assistantComposition(array $assets): array
{
    $total = array_sum(array_column($assets, "valor_atual"));
    if ($total <= 0) {
        return ["total" => 0.0, "asset_count" => 0, "categories" => [], "asset_hhi" => null, "category_hhi" => null, "largest_asset" => null];
    }

    $categories = [];
    $assetHhi = 0.0;
    $largest = null;
    foreach ($assets as $asset) {
        $weight = $asset["valor_atual"] / $total;
        $assetHhi += $weight * $weight;
        $categories[$asset["categoria"]] = ($categories[$asset["categoria"]] ?? 0) + $weight;
        if ($largest === null || $weight > $largest["weight"]) {
            $largest = ["symbol" => $asset["simbolo"], "weight" => $weight];
        }
    }
    arsort($categories);

    $categoryHhi = 0.0;
    foreach ($categories as $weight) {
        $categoryHhi += $weight * $weight;
    }

    return [
        "total" => $total,
        "asset_count" => count($assets),
        "categories" => $categories,
        "asset_hhi" => $assetHhi,
        "category_hhi" => $categoryHhi,
        "largest_asset" => $largest,
    ];
}

/** Score from 0 to 100: half from the split between categories, half from the split between assets. */
function assistantDiversification(array $composition, string $basis): array
{
    if ($composition["asset_hhi"] === null) {
        return ["score" => null, "label" => "Sem ativos", "basis" => $basis];
    }

    $score = (int) round(100 * (1 - (0.5 * $composition["category_hhi"] + 0.5 * $composition["asset_hhi"])));
    $label = $score >= 65 ? "Diversificada" : ($score >= 35 ? "Pouco diversificada" : "Concentrada");

    return ["score" => $score, "label" => $label, "basis" => $basis];
}

/** Weighted category risk: all fixed income = 1, half fixed income and half stocks = 2.5, all stocks = 4. */
function assistantRisk(array $composition): array
{
    if (!$composition["categories"]) {
        return ["level" => null, "label" => "Sem ativos"];
    }

    $average = 0.0;
    foreach ($composition["categories"] as $category => $weight) {
        $average += $weight * (ASSISTANT_CATEGORY_RISK[$category] ?? 3);
    }

    $label = $average < 2 ? "Conservador" : ($average <= 3.4 ? "Moderado" : "Arrojado");
    return ["level" => round($average, 1), "label" => $label];
}

/** Completed lessons per course trail, keyed by the lowercase trail title. */
function assistantTrailProgress(PDO $connection, int $userId): array
{
    try {
        $statement = $connection->prepare("
            SELECT t.id_trilha, t.titulo_trilha, t.ordem_trilha,
                   COUNT(a.id_atividade) AS total,
                   COUNT(ip.id_item_progresso) FILTER (WHERE ip.concluido_progresso) AS concluidas
              FROM trilha t
              LEFT JOIN atividade a ON a.id_trilha = t.id_trilha
              LEFT JOIN item_progresso ip ON ip.id_atividade = a.id_atividade AND ip.id_usuario = :user_id
             GROUP BY t.id_trilha, t.titulo_trilha, t.ordem_trilha
             ORDER BY t.ordem_trilha
        ");
        $statement->execute([":user_id" => $userId]);
    } catch (PDOException $error) {
        error_log("Assistant: trail progress unavailable: " . $error->getMessage());
        return [];
    }

    $trails = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $trails[mb_strtolower($row["titulo_trilha"], "UTF-8")] = [
            "trail_id" => (int) $row["id_trilha"],
            "trail" => $row["titulo_trilha"],
            "completed" => (int) $row["concluidas"],
            "total" => (int) $row["total"],
        ];
    }
    return $trails;
}

/** Up to two unfinished trails to study now, each with the reason. */
function assistantRecommendations(array $trails, array $composition, int $operations, bool $hasSimulation): array
{
    $unfinished = static function (string $title) use ($trails): ?array {
        $trail = $trails[mb_strtolower($title, "UTF-8")] ?? null;
        return $trail && $trail["total"] > 0 && $trail["completed"] < $trail["total"] ? $trail : null;
    };
    $suggestions = [];
    $add = static function (?array $trail, string $reason) use (&$suggestions): void {
        if ($trail === null || count($suggestions) >= 2 || in_array($trail["trail_id"], array_column($suggestions, "trail_id"), true)) {
            return;
        }
        $suggestions[] = $trail + ["reason" => $reason];
    };

    if ($composition["asset_count"] === 0 || $operations < 3) {
        $add($unfinished("Iniciante"), "Começar pelo básico: como funcionam as carteiras, os tipos de investimento e os riscos.");
    }

    $categories = $composition["categories"];
    if (isset($categories["Cripto"])) {
        $add($unfinished("Cripto"), "Você tem criptomoedas: vale entender por que o preço delas oscila tanto.");
    }
    if ($composition["asset_count"] > 0 && !isset($categories["Renda Fixa"])) {
        $add($unfinished("Renda Fixa"), "Ainda não há renda fixa: é a base para reserva de emergência e objetivos de curto prazo.");
    }
    foreach (array_keys($categories) as $category) {
        $title = ASSISTANT_CATEGORY_TRAIL[$category] ?? null;
        if ($title !== null) {
            $add($unfinished($title), "A maior parte do seu dinheiro está em $category: entender bem a categoria ajuda a acompanhar o resultado.");
        }
    }
    if (!$hasSimulation) {
        $add($unfinished("Iniciante"), "Antes de arriscar o saldo real, uma carteira simulada ajuda a praticar.");
    }

    return $suggestions;
}

function assistantOverviewText(array $real, bool $hasAssets, int $seed): string
{
    if (!$hasAssets) {
        return assistantVary([
            "Você ainda não tem ativos em nenhuma carteira, então este é um bom momento para começar sem pressa. ",
            "Sua conta ainda está sem ativos: dá para começar com calma, sem colocar o saldo real em jogo. ",
        ], $seed) . "Uma carteira simulada usa dinheiro fictício e permite testar compras e vendas para ver como o preço de cada ativo se comporta.";
    }

    if ((float) $real["total_investido"] <= 0) {
        return "Por enquanto você investe só nas carteiras simuladas, com dinheiro fictício. É a melhor forma de praticar antes de usar o saldo real.";
    }

    $result = (float) $real["lucro_prejuizo"];
    $returnPercent = (float) $real["rentabilidade_percentual"];
    $text = "Suas carteiras reais têm " . formatCurrency((float) $real["valor_mercado"]) . " aplicados hoje, de "
        . formatCurrency((float) $real["total_investido"]) . " investidos. ";

    if ($result >= 0) {
        return $text . assistantVary([
            "O resultado até aqui é positivo: " . formatPercent($returnPercent, true) . " (" . formatCurrency($result) . "). ",
            "Até agora os ativos valorizaram " . formatPercent($returnPercent) . " (" . formatCurrency($result) . "). ",
        ], $seed) . "Lembre que resultado de curto prazo não garante o próximo mês.";
    }

    return $text . "Os ativos estão " . formatPercent($returnPercent) . " abaixo do valor pago (" . formatCurrency($result) . "). "
        . "Quedas fazem parte do mercado; o que pesa é o prazo do objetivo e o motivo de cada compra.";
}

function assistantDiversificationText(array $composition, array $diversification, array $risk): ?string
{
    if ($diversification["score"] === null) {
        return null;
    }

    $mainCategory = array_key_first($composition["categories"]);
    $categoryCount = count($composition["categories"]);
    $text = "Nas " . $diversification["basis"] . ", a nota de diversificação é " . $diversification["score"] . " de 100 ("
        . mb_strtolower($diversification["label"], "UTF-8") . "). ";

    if ($composition["asset_count"] === 1) {
        $text .= "Há um único ativo (" . $composition["largest_asset"]["symbol"] . "), então o resultado depende só dele. ";
    } elseif ($categoryCount === 1) {
        $text .= "Os " . $composition["asset_count"] . " ativos são todos de $mainCategory: se essa categoria cair, cai tudo junto. ";
    } else {
        $text .= "A maior fatia está em $mainCategory (" . formatPercent(round($composition["categories"][$mainCategory] * 100, 1)) . "), espalhada por "
            . $composition["asset_count"] . " ativos em $categoryCount categorias. ";
    }

    $text .= "O perfil de risco fica " . mb_strtolower($risk["label"], "UTF-8") . ": " . match ($risk["label"]) {
        "Conservador" => "a maior parte está em investimentos que oscilam pouco.",
        "Moderado" => "mistura investimentos estáveis com outros que sobem e descem mais.",
        default => "boa parte está em investimentos que podem subir ou cair muito em pouco tempo.",
    };

    if (isset($composition["categories"]["Cripto"]) || isset($composition["categories"]["Futuros"])) {
        $text .= " Criptomoedas e contratos futuros são os mais voláteis: oscilações de 10% num dia não são raras.";
    }
    return $text;
}

function assistantSimulationText(array $simulated, int $seed): ?string
{
    $walletCount = (int) $simulated["quantidade_carteiras"];
    if ($walletCount === 0) {
        return null;
    }

    return "Nas " . formatCount($walletCount, "carteira simulada", "carteiras simuladas")
        . ", o resultado é " . formatPercent((float) $simulated["rentabilidade_percentual"], true)
        . " sobre o capital de simulação. "
        . assistantVary([
            "Use as simulações para testar ideias, como dividir o dinheiro de outro jeito, e compare depois com a carteira real.",
            "Elas são o lugar certo para errar sem prejuízo: teste estratégias diferentes e veja qual se comporta melhor.",
        ], $seed);
}

function assistantHabitsText(int $operations, int $days, ?float $idleCashPercent, array $wallets, array $indicators): string
{
    $text = $operations === 0
        ? "Não houve compras nem vendas nos últimos $days dias. "
        : "Nos últimos $days dias você fez " . formatCount($operations, "operação", "operações") . " entre compras e vendas. ";

    if ($operations > 20) {
        $text .= "É bastante movimento: comprar e vender com muita frequência costuma aumentar custos e ansiedade. ";
    }
    if ($idleCashPercent !== null && $idleCashPercent >= 50) {
        $text .= formatPercent($idleCashPercent) . " do patrimônio real está como saldo disponível, sem render. ";
    }
    if (!empty($indicators["selic"])) {
        $text .= "Como régua, a Selic está em " . formatPercent((float) $indicators["selic"]) . " ao ano"
            . (!empty($indicators["cdi"]) ? " e o CDI perto de " . formatPercent((float) $indicators["cdi"]) : "")
            . ": é mais ou menos o que um título pós-fixado rende em um ano.";
    }
    foreach ($wallets as $wallet) {
        if ((float) $wallet["meta_carteira"] > 0 && !$wallet["meta_atingida"] && (float) $wallet["progresso_meta"] >= 75) {
            $text .= " A carteira " . $wallet["nome_carteira"] . " já está em " . formatPercent((float) $wallet["progresso_meta"]) . " da meta.";
            break;
        }
    }
    return trim($text);
}

/**
 * Full analysis from the report, every asset held (simbolo, categoria, simulada, valor_atual,
 * rentabilidade_percentual) and the economic indicators (selic and cdi, % a year).
 */
function assistantAnalyze(PDO $connection, int $userId, array $report, array $assets, array $indicators): array
{
    $real = $report["real"];
    $simulated = $report["simulado"];
    $activity = $report["movimentacao"];
    $seed = (int) sprintf("%u", crc32($userId . date("Y-m-d")));

    $realAssets = array_values(array_filter($assets, static fn(array $asset): bool => !$asset["simulada"] && $asset["valor_atual"] > 0));
    $simulatedAssets = array_values(array_filter($assets, static fn(array $asset): bool => $asset["simulada"] && $asset["valor_atual"] > 0));
    $composition = assistantComposition($realAssets ?: $simulatedAssets);
    $basis = $realAssets ? "carteiras reais" : ($simulatedAssets ? "carteiras simuladas" : "nenhuma carteira");

    $diversification = assistantDiversification($composition, $basis);
    $risk = assistantRisk($composition);
    $realNetWorth = (float) $real["patrimonio_total"];
    $idleCashPercent = $realNetWorth > 0 ? round((float) $report["saldo_geral"] / $realNetWorth * 100, 1) : null;
    $operations = $activity["real"]["compras"] + $activity["real"]["vendas"] + $activity["simulada"]["compras"] + $activity["simulada"]["vendas"];

    $paragraphs = array_values(array_filter([
        assistantOverviewText($real, (bool) $assets, $seed),
        assistantDiversificationText($composition, $diversification, $risk),
        assistantSimulationText($simulated, $seed),
        assistantHabitsText($operations, (int) $report["periodo"]["dias"], $idleCashPercent, $report["carteiras"], $indicators),
    ]));

    return [
        "engine" => ASSISTANT_ENGINE,
        "paragraphs" => $paragraphs,
        "diagnosis" => [
            "diversification" => $diversification,
            "risk" => $risk,
            "idle_cash_percent" => $idleCashPercent,
        ],
        "study" => assistantRecommendations(
            assistantTrailProgress($connection, $userId),
            $composition,
            $operations,
            (int) $simulated["quantidade_carteiras"] > 0
        ),
    ];
}
