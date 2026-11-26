<?php

// Análise do relatório escrita por IA (Claude, da Anthropic). É opcional: sem a variável
// ANTHROPIC_API_KEY o relatório continua completo, só sem este texto. Para a IA vai apenas o
// resumo numérico do relatório e os nomes das carteiras (nada de nome, e-mail ou telefone).
// ANTHROPIC_MODEL troca o modelo (padrão claude-opus-5-5).

require_once __DIR__ . "/../../vendor/autoload.php";

use Anthropic\Client;
use Anthropic\RequestOptions;

const ANALISE_IA_MODELO_PADRAO = "claude-opus-5-5";
const ANALISE_IA_INTERVALO_MIN = 30;   // uma análise nova a cada 30 minutos por pessoa
const ANALISE_IA_TIMEOUT_S = 40.0;
const ANALISE_IA_BETA_FALLBACK = "server-side-fallback-2026-07-01";

const ANALISE_IA_INSTRUCOES = <<<TXT
Você escreve a análise de um relatório do Accuracy, um simulador educacional de investimentos usado por estudantes.
Escreva em português do Brasil, com linguagem simples, para quem está começando a investir.
Explique o que os números mostram: o resultado das carteiras reais e das simuladas, como o dinheiro está dividido entre as categorias, se um ativo pesa demais, quanto está como saldo sem aplicação, as metas e a movimentação do período.
Termine com uma ou duas ideias do que a pessoa pode estudar a seguir (por exemplo diversificação, renda fixa, prazo ou risco).
Regras: use só os números recebidos e não invente dados; não recomende comprar, vender ou manter ativos específicos; não prometa rentabilidade; lembre que as carteiras simuladas usam dinheiro fictício.
Formato: no máximo 4 parágrafos curtos, sem títulos, listas ou markdown.
TXT;

function analiseIaConfigurada(): bool
{
    $chave = $_ENV["ANTHROPIC_API_KEY"] ?? getenv("ANTHROPIC_API_KEY");
    return is_string($chave) && trim($chave) !== "";
}

function analiseIaModelo(): string
{
    $modelo = $_ENV["ANTHROPIC_MODEL"] ?? getenv("ANTHROPIC_MODEL");
    return is_string($modelo) && trim($modelo) !== "" ? trim($modelo) : ANALISE_IA_MODELO_PADRAO;
}

/**
 * Última análise da pessoa, com quantos segundos faltam para poder pedir outra.
 * Devolve null se não houver nenhuma (ou se a tabela ainda não existir no banco).
 */
function analiseIaUltima(PDO $conexao, int $idUsuario): ?array
{
    try {
        $stmt = $conexao->prepare("
            SELECT texto_analise,
                   to_char(criado_em, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS gerado_em,
                   EXTRACT(EPOCH FROM (NOW() - criado_em)) AS segundos
              FROM relatorio_analise
             WHERE id_usuario = :usuario
             ORDER BY criado_em DESC
             LIMIT 1
        ");
        $stmt->execute([":usuario" => $idUsuario]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $erro) {
        error_log("Análise com IA: tabela relatorio_analise indisponível: " . $erro->getMessage());
        return null;
    }

    if (!$linha) {
        return null;
    }

    return [
        "texto" => $linha["texto_analise"],
        "gerado_em" => $linha["gerado_em"],
        "espera_segundos" => max(0, (int) ceil(ANALISE_IA_INTERVALO_MIN * 60 - (float) $linha["segundos"])),
    ];
}

/** O que a IA recebe: só números e nomes de carteira. */
function analiseIaDados(array $relatorio): array
{
    $ativo = static fn(array $a): array => [
        "simbolo" => $a["simbolo"],
        "categoria" => $a["categoria"],
        "carteira_simulada" => $a["simulada"],
        "rentabilidade_percentual" => $a["rentabilidade_percentual"],
    ];

    return [
        "periodo_dias" => $relatorio["periodo"]["dias"],
        "saldo_disponivel" => $relatorio["saldo_geral"],
        "carteiras_reais" => $relatorio["real"],
        "carteiras_simuladas" => $relatorio["simulado"],
        "carteiras" => array_map(static fn(array $c): array => [
            "nome" => $c["nome_carteira"],
            "tipo" => $c["tipo_carteira"],
            "estilo" => $c["estilo_carteira"],
            "patrimonio_atual" => $c["patrimonio_atual"],
            "total_investido" => $c["total_investido"],
            "resultado" => $c["lucro_prejuizo"],
            "rentabilidade_percentual" => $c["rentabilidade_percentual"],
            "quantidade_ativos" => $c["quantidade_ativos"],
            "meta" => $c["meta_carteira"],
            "progresso_meta_percentual" => $c["progresso_meta"],
        ], $relatorio["carteiras"]),
        "distribuicao_carteiras_reais" => $relatorio["distribuicao"],
        "maiores_altas" => array_map($ativo, $relatorio["destaques"]["melhores"]),
        "maiores_quedas" => array_map($ativo, $relatorio["destaques"]["piores"]),
        "movimentacao_no_periodo" => $relatorio["movimentacao"],
    ];
}

/**
 * Pede a análise ao Claude e guarda o texto.
 *
 * @throws \Anthropic\Core\Exceptions\APIException quando a API da Anthropic falha ou demora
 * @throws RuntimeException quando a resposta vem sem texto ou é recusada
 */
function gerarAnaliseIa(PDO $conexao, int $idUsuario, array $relatorio): array
{
    // O SDK repassa o tempo limite ao cliente HTTP: o Guzzle é quem corta a espera.
    $cliente = new Client(requestOptions: RequestOptions::with(
        maxRetries: 1,
        transporter: new \GuzzleHttp\Client(["timeout" => ANALISE_IA_TIMEOUT_S, "connect_timeout" => 10]),
    ));

    $resposta = $cliente->beta->messages->create(
        maxTokens: 4096,
        messages: [[
            "role" => "user",
            "content" => "Dados do relatório em JSON:\n"
                . json_encode(analiseIaDados($relatorio), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]],
        model: analiseIaModelo(),
        system: ANALISE_IA_INSTRUCOES,
        outputConfig: ["effort" => "low"],
        // Se o modelo recusar por política, a própria API tenta o modelo reserva padrão.
        fallbacks: "default",
        betas: [ANALISE_IA_BETA_FALLBACK],
    );

    if ($resposta->stopReason === "refusal") {
        throw new RuntimeException("A IA recusou o pedido de análise.");
    }

    $texto = "";
    foreach ($resposta->content as $bloco) {
        if ($bloco->type === "text") {
            $texto .= $bloco->text;
        }
    }
    $texto = trim($texto);

    if ($texto === "") {
        throw new RuntimeException("A IA respondeu sem texto (stop_reason: " . ($resposta->stopReason ?? "?") . ").");
    }

    $stmt = $conexao->prepare("
        INSERT INTO relatorio_analise (id_usuario, texto_analise, modelo_analise)
        VALUES (:usuario, :texto, :modelo)
        RETURNING to_char(criado_em, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')
    ");
    $stmt->execute([":usuario" => $idUsuario, ":texto" => $texto, ":modelo" => $resposta->model]);

    return [
        "texto" => $texto,
        "gerado_em" => $stmt->fetchColumn(),
        "espera_segundos" => ANALISE_IA_INTERVALO_MIN * 60,
    ];
}
