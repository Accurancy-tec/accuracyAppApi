<?php
require_once __DIR__ . '/../src/Controllers/RelatorioController.php';

$acao = $partes[1] ?? null;

switch ($acao) {

    case 'resumo':
        relatorioResumoController();
        break;

    case 'analise':
        relatorioAnaliseController();
        break;

    default:
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Ação não encontrada'
        ]);
        break;
}
