<?php
/**
 * Define a função loginGoogle() que o routes/auth.php chama.
 */

require_once __DIR__ . '/../src/Repositories/OAuthRepository.php';
require_once __DIR__ . '/../src/Services/GoogleAuthService.php';
require_once __DIR__ . '/../src/Controllers/GoogleAuthController.php';

use App\Controllers\GoogleAuthController;
use App\Repositories\OAuthRepository;
use App\Services\GoogleAuthService;

function loginGoogle()
{
    global $conexao;

    (new GoogleAuthController(
        new GoogleAuthService(),
        new OAuthRepository($conexao)
    ))->loginGoogle();
}