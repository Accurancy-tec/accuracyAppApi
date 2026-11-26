<?php

// Relatório da conta: usa o patrimônio já calculado (PatrimonioService), soma a movimentação
// do período e escreve observações educativas a partir dos números. Nada aqui é recomendação
// de investimento: as frases explicam o que os números mostram.

require_once __DIR__ . "/PatrimonioService.php";

const RELATORIO_DIAS_PADRAO = 30;
const RELATORIO_DIAS_MAXIMO = 365;
const RELATORIO_CONCENTRACAO_CATEGORIA = 60.0; // % de uma categoria na carteira real
const RELATORIO_CONCENTRACAO_ATIVO = 40.0;     // % de um único ativo na carteira real
const RELATORIO_SALDO_PARADO = 50.0;           // % do patrimônio real como saldo disponível
const RELATORIO_AVISO = "Relatório educativo, feito com os dados da sua conta no simulador. Não é recomendação de investimento.";

function relatorioMoeda(float $valor): string
{
    return "R$ " . number_format($valor, 2, ",", ".");
}

/** 3.42 -> "3,42%"; 78.5 -> "78,5%"; 60 -> "60%". */
function relatorioPercentual(float $valor, bool $comSinal = false): string
{
    $numero = number_format(abs($valor), 2, ",", ".");
    $texto = rtrim(rtrim($numero, "0"), ",") . "%";
    if (!$comSinal) {
        return $texto;
    }
    return ($valor > 0 ? "+" : ($valor < 0 ? "-" : "")) . $texto;
}

function relatorioPlural(int $quantidade, string $singular, string $plural): string
{
    return $quantidade . " " . ($quantidade === 1 ? $singular : $plural);
}

/** Compras, vendas e dividendos concluídos e depósitos do período, separados entre carteiras reais e simuladas. */
function relatorioMovimentacao(PDO $conexao, int $idUsuario, string $inicio): array
{
    $vazio = ["compras" => 0, "vendas" => 0, "dividendos" => 0, "valor_comprado" => 0.0, "valor_vendido" => 0.0, "valor_dividendos" => 0.0];
    $porTipo = ["Real" => $vazio, "Simulada" => $vazio];
    $chaves = ["Compra" => ["compras", "valor_comprado"], "Venda" => ["vendas", "valor_vendido"], "Dividendo" => ["dividendos", "valor_dividendos"]];

    $stmt = $conexao->prepare("
        SELECT c.tipo_carteira::text AS tipo_carteira,
               a.tipo_aporte::text AS tipo_aporte,
               COUNT(*) AS quantidade,
               COALESCE(SUM(a.valor_aporte), 0) AS valor
          FROM aporte a
          JOIN carteira c ON c.id_carteira = a.id_carteira
         WHERE c.id_usuario = :usuario
           AND a.status_aporte = 'Concluída'
           AND a.data_aporte >= CAST(:inicio AS date)
         GROUP BY 1, 2
    ");
    $stmt->execute([":usuario" => $idUsuario, ":inicio" => $inicio]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $tipoCarteira = $linha["tipo_carteira"] === "Simulada" ? "Simulada" : "Real";
        if (!isset($chaves[$linha["tipo_aporte"]])) {
            continue;
        }
        [$contador, $soma] = $chaves[$linha["tipo_aporte"]];
        $porTipo[$tipoCarteira][$contador] += (int) $linha["quantidade"];
        $porTipo[$tipoCarteira][$soma] += (float) $linha["valor"];
    }

    $stmt = $conexao->prepare("
        SELECT COUNT(*) AS quantidade, COALESCE(SUM(valor), 0) AS valor
          FROM transacao_saldo
         WHERE id_usuario = :usuario AND tipo_transacao = 'Deposito'
           AND data_transacao >= CAST(:inicio AS date)
    ");
    $stmt->execute([":usuario" => $idUsuario, ":inicio" => $inicio]);
    $depositos = $stmt->fetch(PDO::FETCH_ASSOC) ?: ["quantidade" => 0, "valor" => 0];

    $arredondar = static function (array $grupo): array {
        foreach (["valor_comprado", "valor_vendido", "valor_dividendos"] as $chave) {
            $grupo[$chave] = round($grupo[$chave], 2);
        }
        return $grupo;
    };

    return [
        "real" => $arredondar($porTipo["Real"]),
        "simulada" => $arredondar($porTipo["Simulada"]),
        "depositos" => (int) $depositos["quantidade"],
        "valor_depositos" => round((float) $depositos["valor"], 2),
    ];
}

/** Todos os ativos em carteira, com o nome e o tipo da carteira de cada um. */
function relatorioAtivos(array $carteiras): array
{
    $ativos = [];
    foreach ($carteiras as $carteira) {
        foreach ($carteira["ativos"] ?? [] as $ativo) {
            $ativos[] = [
                "simbolo" => $ativo["simbolo_ativo"],
                "nome" => $ativo["nome_ativo"],
                "categoria" => $ativo["categoria_ativo"],
                "carteira" => $carteira["nome_carteira"],
                "simulada" => $carteira["tipo_carteira"] === "Simulada",
                "valor_investido" => $ativo["valor_investido"],
                "valor_atual" => $ativo["valor_atual"],
                "lucro_prejuizo" => $ativo["lucro_prejuizo"],
                "rentabilidade_percentual" => $ativo["rentabilidade_percentual"],
            ];
        }
    }
    return $ativos;
}

/** Os três ativos que mais subiram e os três que mais caíram desde a compra. */
function relatorioDestaques(array $ativos): array
{
    $comCusto = array_values(array_filter($ativos, static fn($a) => $a["valor_investido"] > 0));
    usort($comCusto, static fn($a, $b) => $b["rentabilidade_percentual"] <=> $a["rentabilidade_percentual"]);

    $melhores = array_values(array_filter(array_slice($comCusto, 0, 3), static fn($a) => $a["rentabilidade_percentual"] > 0));
    $piores = array_values(array_filter(array_reverse(array_slice($comCusto, -3)), static fn($a) => $a["rentabilidade_percentual"] < 0));

    return ["melhores" => $melhores, "piores" => $piores];
}

/** Frases curtas que explicam os números (sem dizer o que comprar ou vender). */
function relatorioObservacoes(array $patrimonio, array $ativos, array $movimentacao, int $dias): array
{
    $real = $patrimonio["real"];
    $simulado = $patrimonio["simulado"];
    $observacoes = [];

    if (!$ativos) {
        $observacoes[] = [
            "tipo" => "inicio",
            "texto" => "Você ainda não tem ativos. Uma carteira simulada é um bom começo: você escolhe quanto dinheiro de mentira colocar e pratica compras e vendas sem risco.",
        ];
    }

    if ($real["total_investido"] > 0) {
        $percentual = (float) $real["rentabilidade_percentual"];
        $observacoes[] = $percentual >= 0
            ? ["tipo" => "positivo", "texto" => "Seus investimentos reais estão " . relatorioPercentual($percentual, true) . " em relação ao valor aplicado (" . relatorioMoeda((float) $real["lucro_prejuizo"]) . ")."]
            : ["tipo" => "atencao", "texto" => "Seus investimentos reais estão " . relatorioPercentual($percentual, true) . " em relação ao valor aplicado. Oscilações fazem parte do mercado; o que pesa é o prazo e o motivo de cada compra."];
    }

    $ativosReais = array_values(array_filter($ativos, static fn($a) => !$a["simulada"] && $a["valor_atual"] > 0));
    $distribuicao = $patrimonio["distribuicao"];
    if (count($ativosReais) === 1) {
        $unico = $ativosReais[0];
        $observacoes[] = ["tipo" => "atencao", "texto" => "A carteira real tem um só ativo (" . $unico["simbolo"] . ", " . $unico["categoria"] . "). Com um ativo só, o resultado depende totalmente dele: vale estudar como a diversificação funciona."];
    } elseif ($distribuicao) {
        $maior = $distribuicao[0];
        if (count($distribuicao) === 1) {
            $observacoes[] = ["tipo" => "atencao", "texto" => "Toda a carteira real está em " . $maior["categoria"] . ". Com uma só categoria, o resultado depende só dela: vale estudar como a diversificação funciona."];
        } elseif ($maior["percentual"] >= RELATORIO_CONCENTRACAO_CATEGORIA) {
            $observacoes[] = ["tipo" => "atencao", "texto" => relatorioPercentual((float) $maior["percentual"]) . " da carteira real está em " . $maior["categoria"] . ". Quanto mais concentrada, mais o resultado depende dessa categoria."];
        } else {
            $observacoes[] = ["tipo" => "positivo", "texto" => "A carteira real está dividida em " . count($distribuicao) . " categorias; a maior (" . $maior["categoria"] . ") tem " . relatorioPercentual((float) $maior["percentual"]) . "."];
        }

        $mercadoReal = (float) $real["valor_mercado"];
        foreach ($ativosReais as $ativo) {
            $peso = $mercadoReal > 0 ? $ativo["valor_atual"] / $mercadoReal * 100 : 0;
            if ($peso >= RELATORIO_CONCENTRACAO_ATIVO) {
                $observacoes[] = ["tipo" => "atencao", "texto" => $ativo["simbolo"] . " sozinho representa " . relatorioPercentual(round($peso, 1)) . " da carteira real."];
                break;
            }
        }
    }

    if ($ativosReais && !in_array("Renda Fixa", array_column($distribuicao, "categoria"), true)) {
        $observacoes[] = ["tipo" => "info", "texto" => "Ainda não há renda fixa nas carteiras reais. Ela costuma ser usada para objetivos de curto prazo e para a reserva de emergência."];
    }

    $patrimonioReal = (float) $real["patrimonio_total"];
    if ($patrimonioReal > 0) {
        $parado = (float) $patrimonio["saldo_geral"] / $patrimonioReal * 100;
        if ($parado >= RELATORIO_SALDO_PARADO) {
            $observacoes[] = ["tipo" => "info", "texto" => relatorioPercentual(round($parado, 1)) . " do seu patrimônio real está como saldo disponível, ainda sem aplicação."];
        }
    }

    if ((int) $simulado["quantidade_carteiras"] > 0) {
        $observacoes[] = [
            "tipo" => (float) $simulado["resultado"] >= 0 ? "positivo" : "info",
            "texto" => "Nas " . relatorioPlural((int) $simulado["quantidade_carteiras"], "carteira simulada", "carteiras simuladas")
                . ", o resultado é " . relatorioPercentual((float) $simulado["rentabilidade_percentual"], true)
                . " sobre o capital de simulação (" . relatorioMoeda((float) $simulado["resultado"]) . ").",
        ];
    }

    foreach ($patrimonio["carteiras"] as $carteira) {
        if ((float) $carteira["meta_carteira"] <= 0) {
            continue;
        }
        if ($carteira["meta_atingida"]) {
            $observacoes[] = ["tipo" => "positivo", "texto" => "A carteira " . $carteira["nome_carteira"] . " atingiu a meta de " . relatorioMoeda((float) $carteira["meta_carteira"]) . "."];
        } elseif ((float) $carteira["progresso_meta"] >= 50) {
            $observacoes[] = ["tipo" => "info", "texto" => "A carteira " . $carteira["nome_carteira"] . " está em " . relatorioPercentual((float) $carteira["progresso_meta"]) . " da meta."];
        }
    }

    $movReal = $movimentacao["real"];
    $movSimulada = $movimentacao["simulada"];
    $compras = $movReal["compras"] + $movSimulada["compras"];
    $vendas = $movReal["vendas"] + $movSimulada["vendas"];
    $dividendos = $movReal["dividendos"] + $movSimulada["dividendos"];
    $observacoes[] = $compras + $vendas === 0
        ? ["tipo" => "info", "texto" => "Nenhuma compra ou venda nos últimos $dias dias."]
        : ["tipo" => "info", "texto" => "Nos últimos $dias dias: "
            . relatorioPlural($compras, "compra", "compras") . ", "
            . relatorioPlural($vendas, "venda", "vendas") . " e "
            . relatorioPlural($dividendos, "dividendo registrado", "dividendos registrados") . "."];

    return $observacoes;
}

/** Relatório completo dos últimos $dias dias. */
function montarRelatorioUsuario(PDO $conexao, int $idUsuario, int $dias = RELATORIO_DIAS_PADRAO): array
{
    $dias = max(1, min(RELATORIO_DIAS_MAXIMO, $dias));
    $fuso = new DateTimeZone("America/Sao_Paulo");
    $hoje = new DateTimeImmutable("today", $fuso);
    $inicio = $hoje->modify("-" . ($dias - 1) . " days");

    $patrimonio = montarPatrimonioUsuario($conexao, $idUsuario);
    $ativos = relatorioAtivos($patrimonio["carteiras"]);
    $movimentacao = relatorioMovimentacao($conexao, $idUsuario, $inicio->format("Y-m-d"));

    $carteiras = array_map(static fn(array $c): array => [
        "id_carteira" => $c["id_carteira"],
        "nome_carteira" => $c["nome_carteira"],
        "tipo_carteira" => $c["tipo_carteira"],
        "estilo_carteira" => $c["estilo_carteira"],
        "patrimonio_atual" => $c["patrimonio_atual"],
        "total_investido" => $c["total_investido"],
        "lucro_prejuizo" => $c["tipo_carteira"] === "Simulada" ? ($c["resultado_simulacao"] ?? $c["lucro_prejuizo"]) : $c["lucro_prejuizo"],
        "rentabilidade_percentual" => $c["tipo_carteira"] === "Simulada" ? ($c["rentabilidade_simulacao"] ?? $c["rentabilidade_percentual"]) : $c["rentabilidade_percentual"],
        "quantidade_ativos" => $c["quantidade_ativos"],
        "meta_carteira" => $c["meta_carteira"],
        "progresso_meta" => $c["progresso_meta"],
        "meta_atingida" => $c["meta_atingida"],
    ], $patrimonio["carteiras"]);

    return [
        "periodo" => ["inicio" => $inicio->format("Y-m-d"), "fim" => $hoje->format("Y-m-d"), "dias" => $dias],
        "gerado_em" => (new DateTimeImmutable("now", $fuso))->format(DATE_ATOM),
        "saldo_geral" => $patrimonio["saldo_geral"],
        "real" => $patrimonio["real"],
        "simulado" => $patrimonio["simulado"],
        "carteiras" => $carteiras,
        "distribuicao" => $patrimonio["distribuicao"],
        "destaques" => relatorioDestaques($ativos),
        "movimentacao" => $movimentacao,
        "observacoes" => relatorioObservacoes($patrimonio, $ativos, $movimentacao, $dias),
        "aviso" => RELATORIO_AVISO,
    ];
}
