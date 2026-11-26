<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/ReportService.php";

function sendReportResponse(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** GET relatorios/resumo?dias=30: report numbers, notes and the assistant's analysis. */
function reportSummaryController(): void
{
    global $conexao;

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        sendReportResponse(405, ["success" => false, "message" => "Método não permitido. Use GET."]);
    }

    $userId = (int) autenticar();
    $days = filter_var($_GET["dias"] ?? REPORT_DEFAULT_DAYS, FILTER_VALIDATE_INT, [
        "options" => ["min_range" => 1, "max_range" => REPORT_MAX_DAYS],
    ]);
    if ($days === false) {
        sendReportResponse(400, ["success" => false, "message" => "Período inválido: use de 1 a " . REPORT_MAX_DAYS . " dias."]);
    }

    try {
        $report = buildUserReport($conexao, $userId, $days);
    } catch (Throwable $error) {
        error_log("Report failed: " . $error->getMessage());
        sendReportResponse(500, ["success" => false, "message" => "Não foi possível montar o relatório agora."]);
    }

    sendReportResponse(200, ["success" => true] + $report);
}
