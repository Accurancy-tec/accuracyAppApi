<?php

require_once __DIR__ . '/../src/Controllers/NoticiaController.php';

$acao = $partes[1] ?? null;

switch ($acao) {
    case 'listar':
        listarNoticias();
        break;

    default:
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Ação não encontrada'
        ], JSON_UNESCAPED_UNICODE);
        break;
}
