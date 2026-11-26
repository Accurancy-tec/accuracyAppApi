<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/RelatorioService.php";
require_once __DIR__ . "/../Services/AnaliseIaService.php";

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\RateLimitException;

function responderRelatorio(int $status, array $corpo): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function relatorioDiasValidos($valor): ?int
{
    $dias = filter_var($valor, FILTER_VALIDATE_INT, [
        "options" => ["min_range" => 1, "max_range" => RELATORIO_DIAS_MAXIMO],
    ]);
    return $dias === false ? null : $dias;
}

// GET /relatorios/resumo?dias=30  -> números do relatório, observações e a última análise com IA
function relatorioResumoController(): void
{
    global $conexao;

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        responderRelatorio(405, ["success" => false, "message" => "Método não permitido. Use GET."]);
    }

    $idUsuario = (int) autenticar();
    $dias = relatorioDiasValidos($_GET["dias"] ?? RELATORIO_DIAS_PADRAO);
    if ($dias === null) {
        responderRelatorio(400, ["success" => false, "message" => "Período inválido: use de 1 a " . RELATORIO_DIAS_MAXIMO . " dias."]);
    }

    try {
        $relatorio = montarRelatorioUsuario($conexao, $idUsuario, $dias);
    } catch (Throwable $erro) {
        error_log("Erro ao montar o relatório: " . $erro->getMessage());
        responderRelatorio(500, ["success" => false, "message" => "Não foi possível montar o relatório agora."]);
    }

    $relatorio["analise_ia"] = [
        "disponivel" => analiseIaConfigurada(),
        "ultima" => analiseIaUltima($conexao, $idUsuario),
    ];

    responderRelatorio(200, ["success" => true] + $relatorio);
}

// POST /relatorios/analise  {dias?}  -> texto curto escrito pela IA a partir do relatório
function relatorioAnaliseController(): void
{
    global $conexao;

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        responderRelatorio(405, ["success" => false, "message" => "Método não permitido. Use POST."]);
    }

    $idUsuario = (int) autenticar();

    if (!analiseIaConfigurada()) {
        responderRelatorio(503, ["success" => false, "message" => "A análise com IA não está ativada neste servidor."]);
    }

    $ultima = analiseIaUltima($conexao, $idUsuario);
    if ($ultima !== null && $ultima["espera_segundos"] > 0) {
        $minutos = (int) ceil($ultima["espera_segundos"] / 60);
        responderRelatorio(429, [
            "success" => false,
            "message" => "Você gerou uma análise há pouco. Dá para pedir outra em $minutos min.",
            "analise" => $ultima,
        ]);
    }

    $dados = json_decode((string) file_get_contents("php://input"), true);
    $dias = relatorioDiasValidos(is_array($dados) ? ($dados["dias"] ?? RELATORIO_DIAS_PADRAO) : RELATORIO_DIAS_PADRAO)
        ?? RELATORIO_DIAS_PADRAO;

    try {
        $analise = gerarAnaliseIa($conexao, $idUsuario, montarRelatorioUsuario($conexao, $idUsuario, $dias));
    } catch (APIConnectionException $erro) {
        // inclui o tempo limite (APITimeoutException)
        error_log("Análise com IA: sem resposta da Anthropic: " . $erro->getMessage());
        responderRelatorio(504, ["success" => false, "message" => "A IA demorou para responder. Tente de novo em instantes."]);
    } catch (RateLimitException | OverloadedException $erro) {
        error_log("Análise com IA: limite ou sobrecarga: " . $erro->getMessage());
        responderRelatorio(503, ["success" => false, "message" => "A IA está muito ocupada agora. Tente de novo em alguns minutos."]);
    } catch (AuthenticationException $erro) {
        error_log("Análise com IA: chave da Anthropic recusada.");
        responderRelatorio(503, ["success" => false, "message" => "A análise com IA está indisponível no momento."]);
    } catch (APIStatusException $erro) {
        error_log("Análise com IA: a Anthropic respondeu com erro: " . $erro->getMessage());
        responderRelatorio(502, ["success" => false, "message" => "Não foi possível gerar a análise agora."]);
    } catch (Throwable $erro) {
        error_log("Análise com IA: " . $erro->getMessage());
        responderRelatorio(502, ["success" => false, "message" => "Não foi possível gerar a análise agora."]);
    }

    responderRelatorio(200, ["success" => true, "analise" => $analise]);
}
