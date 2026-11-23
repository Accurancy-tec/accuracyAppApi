<?php

require_once __DIR__ . '/../src/Controllers/BrapiController.php';
require_once __DIR__ . '/../src/Controllers/CriptoController.php';
require_once __DIR__ . '/../src/Controllers/CambioController.php';

$acao = $partes[1] ?? null;

switch ($acao) {
    case 'get-symbols':
        getSymbols();
        break;
    case 'get-quote':
        getQuote();
        break;
    case 'listar-acoes-nacionais':
        listarAcoesNacionais();
        break;
    case 'historico-acoes':
        historicoAcoes();
        break;
    case 'listar-internacionais':
        listarAtivosInternacionais();
        break;
    case 'listar-fiis':
        listarFiis();
        break;
    case 'get-fii-quote':
        getFiiQuote();
        break;
    case 'historico-fiis':
        historicoFiis();
        break;
    case 'listar-futuros':
        listarContratosFuturos();
        break;
    case 'get-futures-quote':
        getFutureQuote();
        break;
    case 'historico-futuros':
        historicoFuturos();
        break;
    case 'patrimonio-carteiras':
        patrimonioCarteirasAcaoController();
        break;
    case 'listar-criptomoedas':
        listarCriptomoedas();
        break;
    case 'get-crypto-quote':
        getCryptoQuote();
        break;
    case 'historico-criptomoedas':
        historicoCriptomoedas();
        break;
    case 'listar-moedas':
        listarMoedas();
        break;
    case 'get-currency-quote':
        getCurrencyQuote();
        break;
    case 'historico-moedas':
        historicoMoedas();
        break;
    default:
        http_response_code(404);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ação da Brapi não encontrada'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;
}