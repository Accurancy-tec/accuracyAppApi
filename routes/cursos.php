<?php
// ROTAS dos cursos: define qual função responde a cada endereço.

require_once __DIR__ . '/../src/Controllers/CursoController.php';

$acao = $partes[1] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);

    exit;
}

switch ($acao) {

    case 'trilhas':
        listarTrilhasController();
        break;

    case 'trilha':
        buscarTrilhaController();
        break;

    default:
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Ação não encontrada'
        ]);
        break;
}
