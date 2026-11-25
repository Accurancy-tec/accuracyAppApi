<?php

require_once __DIR__ . '/../src/Controllers/NotificacaoController.php';

$acao = $partes[1] ?? null;

switch ($acao) {
    case 'listar':
        listarNotificacoes();
        break;

    case 'contador':
        contarNotificacoes();
        break;

    case 'marcar-lidas':
        marcarNotificacoes();
        break;

    default:
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Ação não encontrada'
        ], JSON_UNESCAPED_UNICODE);
        break;
}
