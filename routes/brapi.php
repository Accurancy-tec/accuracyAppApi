<?php
require_once __DIR__ . '/../src/Controllers/BrapiController.php';

$acao = $partes[1] ?? null;

switch ($acao) {

    case 'get-symbols':
        getSymbols();
        break;

    case 'get-quote':
        getQuote();
        break;

    default:

        http_response_code(404);

        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ação não encontrada'
        ]);

        break;
}
