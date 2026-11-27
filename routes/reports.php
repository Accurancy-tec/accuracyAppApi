<?php
require_once __DIR__ . '/../src/Controllers/ReportController.php';

switch ($partes[1] ?? null) {
    case 'resumo':
        reportSummaryController();
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ação não encontrada']);
        break;
}
