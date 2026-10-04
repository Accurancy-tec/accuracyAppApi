<?php
require_once __DIR__ . '/../src/Services/VicoService.php';

$acao = $partes[1] ?? null;

switch ($acao) {

    case 'gerar-grafico':
        gerarGrafico();
        break;

    default:

        http_response_code(404);

        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ação não encontrada'
        ]);

        break;
}