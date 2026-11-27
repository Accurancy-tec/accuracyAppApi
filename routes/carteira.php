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

    case 'resumo':
        resumoPatrimonio();
        break;

    case 'depositar-simulacao':
        depositarSimulacaoController();
        break;

    case 'buscar-posicoes':
        itemInvestimento();
        break;

    case 'distribuicao':
        distribuicaoCarteira();
        break;

    case 'excluir-carteira':
        excluirCarteira();
        break;

    case 'renomear-carteira':
        renameWalletController();
        break;

    case 'posicao-ativo':
        buscarPosicaoAtivoUsuario();
        break;

    case 'adicionar-saldo':
        adicionarSaldoController();
        break;

    case 'saldo':
        buscarSaldoController();
        break;

    default:
        http_response_code(404);

        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ação não encontrada'
        ]);
        break;
}
