<?php

require_once __DIR__ . '/../src/Controllers/FavoritosController.php';

$acao = $partes[1] ?? null;
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($acao) {
    case 'listar':
        listarFavoritosController();
        break;

    case 'adicionar':
        if ($metodo !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Use POST'], JSON_UNESCAPED_UNICODE);
            break;
        }
        adicionarFavoritoController();
        break;

    case 'remover':
        if ($metodo !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Use POST'], JSON_UNESCAPED_UNICODE);
            break;
        }
        removerFavoritoController();
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ação não encontrada'], JSON_UNESCAPED_UNICODE);
        break;
}
