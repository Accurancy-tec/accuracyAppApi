<?php
/**
 * ARQUIVO NOVO. Define as funções loginGoogle() e loginLinkedin() que o
 * routes/auth.php chama (no estilo das outras rotas: funções simples).
 */

require_once __DIR__ . '/../Repositories/OAuthRepository.php';
require_once __DIR__ . '/../Services/GoogleAuthService.php';
require_once __DIR__ . '/../Services/LinkedinAuthService.php';
require_once __DIR__ . '/GoogleAuthController.php';
require_once __DIR__ . '/LinkedinAuthController.php';

use App\Controllers\GoogleAuthController;
use App\Controllers\LinkedinAuthController;
use App\Repositories\OAuthRepository;
use App\Services\GoogleAuthService;
use App\Services\LinkedinAuthService;

function loginGoogle()
{
    global $conexao;

    (new GoogleAuthController(
        new GoogleAuthService(),
        new OAuthRepository($conexao)
    ))->loginGoogle();
}

function loginLinkedin()
{
    global $conexao;

    (new LinkedinAuthController(
        new LinkedinAuthService(),
        new OAuthRepository($conexao)
    ))->loginLinkedin();
}