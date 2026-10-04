<?php

require_once __DIR__ . '/../src/Controllers/AuthController.php';

$acao = $partes[1] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido'
    ]);

    exit;
}

switch ($acao) {

    case 'login':
        login();
        break;

    case 'registrarNovoUsuario':
        registrarUsuario();
        break;

    case 'refresh':
        refresh();
        break;

    case 'verify-email':
        verifyEmail();
        break;

    case 'resend-verification':
        resendVerification();
        break;

    default:

        http_response_code(404);

        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ação não encontrada'
        ]);

        break;
}