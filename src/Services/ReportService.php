<?php

// Account report: the net worth from PatrimonioService, the activity of the period, short
// notes that explain the numbers and the assistant's analysis. Nothing here is advice.

require_once __DIR__ . "/FormatService.php";
require_once __DIR__ . "/PatrimonioService.php";
require_once __DIR__ . "/AssistantService.php";

const REPORT_DEFAULT_DAYS = 30;
const REPORT_MAX_DAYS = 365;
const REPORT_CATEGORY_CONCENTRATION = 60.0; // % of one category in the real wallets
const REPORT_ASSET_CONCENTRATION = 40.0;    // % of a single asset in the real wallets
const REPORT_IDLE_CASH = 50.0;              // % of the real net worth left as available balance
const REPORT_DISCLAIMER = "Relatório educativo, feito com os dados da sua conta no simulador. Não é recomendação de investimento.";

/** Completed purchases, sales and dividends plus deposits of the period, split into real and simulated wallets. */
function reportActivity(PDO $connection, int $userId, string $startDate): array
{
    $empty = ["compras" => 0, "vendas" => 0, "dividendos" => 0, "valor_comprado" => 0.0, "valor_vendido" => 0.0, "valor_dividendos" => 0.0];
    $byWalletType = ["Real" => $empty, "Simulada" => $empty];
    $keysByType = ["Compra" => ["compras", "valor_comprado"], "Venda" => ["vendas", "valor_vendido"], "Dividendo" => ["dividendos", "valor_dividendos"]];

    $statement = $connection->prepare("
        SELECT c.tipo_carteira::text AS tipo_carteira,
               a.tipo_aporte::text AS tipo_aporte,
               COUNT(*) AS quantidade,
               COALESCE(SUM(a.valor_aporte), 0) AS valor
          FROM aporte a
          JOIN carteira c ON c.id_carteira = a.id_carteira
         WHERE c.id_usuario = :user_id
           AND a.status_aporte = 'Concluída'
           AND a.data_aporte >= CAST(:start_date AS date)
         GROUP BY 1, 2
    ");
    $statement->execute([":user_id" => $userId, ":start_date" => $startDate]);

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($keysByType[$row["tipo_aporte"]])) {
            continue;
        }
        $walletType = $row["tipo_carteira"] === "Simulada" ? "Simulada" : "Real";
        [$countKey, $valueKey] = $keysByType[$row["tipo_aporte"]];
        $byWalletType[$walletType][$countKey] += (int) $row["quantidade"];
        $byWalletType[$walletType][$valueKey] += (float) $row["valor"];
    }

    $statement = $connection->prepare("
        SELECT COUNT(*) AS quantidade, COALESCE(SUM(valor), 0) AS valor
          FROM transacao_saldo
         WHERE id_usuario = :user_id AND tipo_transacao = 'Deposito'
           AND data_transacao >= CAST(:start_date AS date)
    ");
    $statement->execute([":user_id" => $userId, ":start_date" => $startDate]);
    $deposits = $statement->fetch(PDO::FETCH_ASSOC) ?: ["quantidade" => 0, "valor" => 0];

    $roundValues = static function (array $group): array {
        foreach (["valor_comprado", "valor_vendido", "valor_dividendos"] as $key) {
            $group[$key] = round($group[$key], 2);
        }
        return $group;
    };

    return [
        "real" => $roundValues($byWalletType["Real"]),
        "simulada" => $roundValues($byWalletType["Simulada"]),
        "depositos" => (int) $deposits["quantidade"],
        "valor_depositos" => round((float) $deposits["valor"], 2),
    ];
}

/** Every asset held, with the name and type of its wallet. */
function reportAssets(array $wallets): array
{
    $assets = [];
    foreach ($wallets as $wallet) {
        foreach ($wallet["ativos"] ?? [] as $asset) {
            $assets[] = [
                "simbolo" => $asset["simbolo_ativo"],
                "nome" => $asset["nome_ativo"],
                "categoria" => $asset["categoria_ativo"],
                "carteira" => $wallet["nome_carteira"],
                "simulada" => $wallet["tipo_carteira"] === "Simulada",
                "valor_investido" => $asset["valor_investido"],
                "valor_atual" => $asset["valor_atual"],
                "lucro_prejuizo" => $asset["lucro_prejuizo"],
                "rentabilidade_percentual" => $asset["rentabilidade_percentual"],
            ];
        }
    }
    return $assets;
}

/** The three assets that rose the most and the three that fell the most since purchase. */
function reportHighlights(array $assets): array
{
    $withCost = array_values(array_filter($assets, static fn(array $asset): bool => $asset["valor_investido"] > 0));
    usort($withCost, static fn(array $a, array $b): int => $b["rentabilidade_percentual"] <=> $a["rentabilidade_percentual"]);

    $best = array_values(array_filter(array_slice($withCost, 0, 3), static fn(array $asset): bool => $asset["rentabilidade_percentual"] > 0));
    $worst = array_values(array_filter(array_reverse(array_slice($withCost, -3)), static fn(array $asset): bool => $asset["rentabilidade_percentual"] < 0));

    return ["melhores" => $best, "piores" => $worst];
}

/** Short sentences that explain the numbers, without saying what to buy or sell. */
function reportNotes(array $netWorth, array $assets, array $activity, int $days): array
{
    $real = $netWorth["real"];
    $simulated = $netWorth["simulado"];
    $notes = [];

    if (!$assets) {
        $notes[] = [
            "tipo" => "inicio",
            "texto" => "Você ainda não tem ativos. Uma carteira simulada é um bom começo: você escolhe quanto dinheiro de mentira colocar e pratica compras e vendas sem risco.",
        ];
    }

    if ($real["total_investido"] > 0) {
        $returnPercent = (float) $real["rentabilidade_percentual"];
        $notes[] = $returnPercent >= 0
            ? ["tipo" => "positivo", "texto" => "Seus investimentos reais estão " . formatPercent($returnPercent, true) . " em relação ao valor aplicado (" . formatCurrency((float) $real["lucro_prejuizo"]) . ")."]
            : ["tipo" => "atencao", "texto" => "Seus investimentos reais estão " . formatPercent($returnPercent, true) . " em relação ao valor aplicado. Oscilações fazem parte do mercado; o que pesa é o prazo e o motivo de cada compra."];
    }

    $realAssets = array_values(array_filter($assets, static fn(array $asset): bool => !$asset["simulada"] && $asset["valor_atual"] > 0));
    $distribution = $netWorth["distribuicao"];
    if (count($realAssets) === 1) {
        $onlyAsset = $realAssets[0];
        $notes[] = ["tipo" => "atencao", "texto" => "A carteira real tem um só ativo (" . $onlyAsset["simbolo"] . ", " . $onlyAsset["categoria"] . "). Com um ativo só, o resultado depende totalmente dele: vale estudar como a diversificação funciona."];
    } elseif ($distribution) {
        $largest = $distribution[0];
        if (count($distribution) === 1) {
            $notes[] = ["tipo" => "atencao", "texto" => "Toda a carteira real está em " . $largest["categoria"] . ". Com uma só categoria, o resultado depende só dela: vale estudar como a diversificação funciona."];
        } elseif ($largest["percentual"] >= REPORT_CATEGORY_CONCENTRATION) {
            $notes[] = ["tipo" => "atencao", "texto" => formatPercent((float) $largest["percentual"]) . " da carteira real está em " . $largest["categoria"] . ". Quanto mais concentrada, mais o resultado depende dessa categoria."];
        } else {
            $notes[] = ["tipo" => "positivo", "texto" => "A carteira real está dividida em " . count($distribution) . " categorias; a maior (" . $largest["categoria"] . ") tem " . formatPercent((float) $largest["percentual"]) . "."];
        }

        $realMarketValue = (float) $real["valor_mercado"];
        foreach ($realAssets as $asset) {
            $weight = $realMarketValue > 0 ? $asset["valor_atual"] / $realMarketValue * 100 : 0;
            if ($weight >= REPORT_ASSET_CONCENTRATION) {
                $notes[] = ["tipo" => "atencao", "texto" => $asset["simbolo"] . " sozinho representa " . formatPercent(round($weight, 1)) . " da carteira real."];
                break;
            }
        }
    }

    if ($realAssets && !in_array("Renda Fixa", array_column($distribution, "categoria"), true)) {
        $notes[] = ["tipo" => "info", "texto" => "Ainda não há renda fixa nas carteiras reais. Ela costuma ser usada para objetivos de curto prazo e para a reserva de emergência."];
    }

    $realNetWorth = (float) $real["patrimonio_total"];
    if ($realNetWorth > 0) {
        $idleCashPercent = (float) $netWorth["saldo_geral"] / $realNetWorth * 100;
        if ($idleCashPercent >= REPORT_IDLE_CASH) {
            $notes[] = ["tipo" => "info", "texto" => formatPercent(round($idleCashPercent, 1)) . " do seu patrimônio real está como saldo disponível, ainda sem aplicação."];
        }
    }

    if ((int) $simulated["quantidade_carteiras"] > 0) {
        $notes[] = [
            "tipo" => (float) $simulated["resultado"] >= 0 ? "positivo" : "info",
            "texto" => "Nas " . formatCount((int) $simulated["quantidade_carteiras"], "carteira simulada", "carteiras simuladas")
                . ", o resultado é " . formatPercent((float) $simulated["rentabilidade_percentual"], true)
                . " sobre o capital de simulação (" . formatCurrency((float) $simulated["resultado"]) . ").",
        ];
    }

    foreach ($netWorth["carteiras"] as $wallet) {
        if ((float) $wallet["meta_carteira"] <= 0) {
            continue;
        }
        if ($wallet["meta_atingida"]) {
            $notes[] = ["tipo" => "positivo", "texto" => "A carteira " . $wallet["nome_carteira"] . " atingiu a meta de " . formatCurrency((float) $wallet["meta_carteira"]) . "."];
        } elseif ((float) $wallet["progresso_meta"] >= 50) {
            $notes[] = ["tipo" => "info", "texto" => "A carteira " . $wallet["nome_carteira"] . " está em " . formatPercent((float) $wallet["progresso_meta"]) . " da meta."];
        }
    }

    $purchases = $activity["real"]["compras"] + $activity["simulada"]["compras"];
    $sales = $activity["real"]["vendas"] + $activity["simulada"]["vendas"];
    $dividends = $activity["real"]["dividendos"] + $activity["simulada"]["dividendos"];
    $notes[] = $purchases + $sales === 0
        ? ["tipo" => "info", "texto" => "Nenhuma compra ou venda nos últimos $days dias."]
        : ["tipo" => "info", "texto" => "Nos últimos $days dias: "
            . formatCount($purchases, "compra", "compras") . ", "
            . formatCount($sales, "venda", "vendas") . " e "
            . formatCount($dividends, "dividendo registrado", "dividendos registrados") . "."];

    return $notes;
}

/** Full report of the last $days days. */
function buildUserReport(PDO $connection, int $userId, int $days = REPORT_DEFAULT_DAYS): array
{
    $days = max(1, min(REPORT_MAX_DAYS, $days));
    $timezone = new DateTimeZone("America/Sao_Paulo");
    $today = new DateTimeImmutable("today", $timezone);
    $start = $today->modify("-" . ($days - 1) . " days");

    $netWorth = montarPatrimonioUsuario($connection, $userId);
    $assets = reportAssets($netWorth["carteiras"]);
    $activity = reportActivity($connection, $userId, $start->format("Y-m-d"));

    $wallets = array_map(static function (array $wallet): array {
        $simulated = $wallet["tipo_carteira"] === "Simulada";
        return [
            "id_carteira" => $wallet["id_carteira"],
            "nome_carteira" => $wallet["nome_carteira"],
            "tipo_carteira" => $wallet["tipo_carteira"],
            "estilo_carteira" => $wallet["estilo_carteira"],
            "patrimonio_atual" => $wallet["patrimonio_atual"],
            "total_investido" => $wallet["total_investido"],
            "lucro_prejuizo" => $simulated ? ($wallet["resultado_simulacao"] ?? $wallet["lucro_prejuizo"]) : $wallet["lucro_prejuizo"],
            "rentabilidade_percentual" => $simulated ? ($wallet["rentabilidade_simulacao"] ?? $wallet["rentabilidade_percentual"]) : $wallet["rentabilidade_percentual"],
            "quantidade_ativos" => $wallet["quantidade_ativos"],
            "meta_carteira" => $wallet["meta_carteira"],
            "progresso_meta" => $wallet["progresso_meta"],
            "meta_atingida" => $wallet["meta_atingida"],
        ];
    }, $netWorth["carteiras"]);

    $report = [
        "periodo" => ["inicio" => $start->format("Y-m-d"), "fim" => $today->format("Y-m-d"), "dias" => $days],
        "gerado_em" => (new DateTimeImmutable("now", $timezone))->format(DATE_ATOM),
        "saldo_geral" => $netWorth["saldo_geral"],
        "real" => $netWorth["real"],
        "simulado" => $netWorth["simulado"],
        "carteiras" => $wallets,
        "distribuicao" => $netWorth["distribuicao"],
        "destaques" => reportHighlights($assets),
        "movimentacao" => $activity,
        "observacoes" => reportNotes($netWorth, $assets, $activity, $days),
        "aviso" => REPORT_DISCLAIMER,
    ];
    try {
        $indicators = indicadoresEconomicos();
    } catch (Throwable $error) {
        error_log("Report: economic indicators unavailable: " . $error->getMessage());
        $indicators = [];
    }
    $report["analysis"] = assistantAnalyze($connection, $userId, $report, $assets, $indicators);

    return $report;
}
