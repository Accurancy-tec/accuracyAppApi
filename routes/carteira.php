<?php

require_once __DIR__ . '/../src/Controllers/CarteiraController.php';

require_once __DIR__ . '/../src/Controllers/AuthController.php';

$acao = $partes[1] ?? null;

switch ($acao) {

    case 'criar-carteira':
        criarCarteira();
        break;

    case 'buscar-carteiras':
        buscarCarteiras();
        break;

    case 'excluir-carteira':
        break; 

    default:

        http_response_code(404);

        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ação não encontrada'
        ]);

        break;
}