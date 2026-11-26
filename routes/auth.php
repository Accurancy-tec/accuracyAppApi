<?php

require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/OAUTH.php'; // define loginGoogle() e loginLinkedin()
require_once __DIR__ . '/../src/Controllers/AdminController.php';

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
    case 'loginSite':
        loginSite();
        break;


    case 'loginGoogle':
        loginGoogle();
        break;

    case 'googleConfig':
        googleConfig();
        break;

    case 'registrarNovoUsuario':
        registrarUsuario();
        break;

    case 'refresh':
        refresh();
        break;
    case 'recuperarSenha':
        recuperarSenha();
        break;
    case 'redefinirSenha':
        redefinirSenha();
        break;
    case 'sessoes':
        sessoesListar();
        break;
    case 'sessaoEncerrar':
        sessaoEncerrarController();
        break;
    case 'sessoesEncerrar':
        sessoesEncerrarController();
        break;
    case 'sair':
        sairDaConta();
        break;

    case 'verify-email':
        verifyEmail();
        break;

    case 'resend-verification':
        resendVerification();
        break;

    case 'perfilObter':
        perfilObter();
        break;

    case 'perfilAtualizar':
        perfilAtualizar();
        break;

    case 'perfilFoto':
        perfilFoto();
        break;

    case 'perfilSolicitarTrocaSenha':
        perfilSolicitarTrocaSenha();
        break;

    case 'perfilConfirmarTrocaSenha':
        perfilConfirmarTrocaSenha();
        break;

    case 'perfilSolicitarTrocaEmail':
        perfilSolicitarTrocaEmail();
        break;

    case 'perfilConfirmarTrocaEmail':
        perfilConfirmarTrocaEmail();
        break;
        
    case 'perfilAutorizarTrocaEmail':
        perfilAutorizarTrocaEmail();
        break;

    case 'perfilAutorizarTrocaEmail':
        perfilAutorizarTrocaEmail();
        break;

    case 'loginUnico':
        loginUnico();
        break;

    case 'adminListarUsuarios':
        adminListarUsuarios();
        break;

    case 'adminAlterarUsuario':
        adminAlterarUsuario();
        break;

    case 'adminDeletarUsuario':
        adminDeletarUsuario();
        break;

    case 'adminCategoriasNoticia':
        adminCategoriasNoticia();
        break;

    case 'adminListarNoticias':
        adminListarNoticias();
        break;

    case 'adminCriarNoticia':
        adminCriarNoticia();
        break;

    case 'adminExcluirNoticia':
        adminExcluirNoticia();
        break;

    case 'adminListarAdmins':
        adminListarAdmins();
        break;

    case 'adminCriarAdmin':
        adminCriarAdmin();
        break;
    case 'adminOpcoesCurso':
        adminOpcoesCurso();
        break;

    case 'adminListarCursos':
        adminListarCursos();
        break;

    case 'adminCriarCurso':
        adminCriarCurso();
        break;

    case 'adminAlterarCurso':
        adminAlterarCurso();
        break;

    case 'adminExcluirCurso':
        adminExcluirCurso();
        break;

    case 'adminListarPerguntas':
        adminListarPerguntas();
        break;

    case 'adminResponderPergunta':
        adminResponderPergunta();
        break;

    case 'adminExcluirPergunta':
        adminExcluirPergunta();
        break;

    case 'perguntaEnviar':
        perguntaEnviar();
        break;

    case 'perguntaListar':
        perguntaListar();
        break;

    default:

        http_response_code(404);

        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ação não encontrada'
        ]);

        break;
}
