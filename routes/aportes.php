<?php

require_once __DIR__ . '/../src/Controllers/AportesController.php';

$acao = $partes[1] ?? null;

switch ($acao) {

    case 'registrar-aporte':
        fazerAporte();
        break;

    case 'buscar-aportes':
        buscarAportesDoUsuario();
        break;

    case 'excluir-aporte':
        excluirAporte();
        break;

    default:

        http_response_code(404);

        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ação não encontrada'
        ]);

        break;
}
