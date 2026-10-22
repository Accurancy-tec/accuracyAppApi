<?php

require_once __DIR__ . '/../src/Controllers/BrapiController.php';

$acao = $partes[1] ?? null;

switch ($acao) {
    case 'get-symbols':
        getSymbols();
        break;

    // Padronizado com ApiService.java: /brapi/get-quote?symbol=PETR4
    case 'get-quote':
        getQuote();
        break;

    case 'listar-acoes-nacionais':
        listarAcoesNacionais();
        break;

    case 'historico-acoes':
        historicoAcoes();
        break;

    default:
        http_response_code(404);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ação da Brapi não encontrada'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;
}
