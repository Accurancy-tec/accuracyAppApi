<?php

namespace App\Controllers;

use App\Repositories\OAuthRepository;
use App\Services\GoogleAuthService;

class GoogleAuthController
{
    public function __construct(
        private GoogleAuthService $googleService,
        private OAuthRepository $oauthRepo
    ) {}

    public function loginGoogle(): void
    {
        header('Content-Type: application/json');

        $input   = json_decode(file_get_contents('php://input'), true);
        $idToken = $input['id_token'] ?? '';

        if (!$idToken) {
            $this->responder(400, ['success' => false, 'message' => 'id_token ausente']);
            return;
        }

        $dados = $this->googleService->validarIdToken($idToken);

        if (!$dados) {
            $this->responder(401, ['success' => false, 'message' => 'Token do Google inválido']);
            return;
        }

        try {
            $idUsuario = $this->oauthRepo->transacao(function () use ($dados) {

                // 1) Já existe vínculo com essa conta Google?
                // O enum provedor_oauth_enum diferencia maiúsculas: o valor é 'Google'
                $id = $this->oauthRepo->buscarUsuarioPorVinculo('Google', $dados['sub']);

                if ($id !== null) {
                    return $id;
                }

                // 2) Já existe usuário com esse e-mail? Se não, cria.
                $existente = $this->oauthRepo->buscarUsuarioPorEmail($dados['email']);
                if ($existente !== null) {
                    // o Google já confirmou esse e-mail
                    $this->oauthRepo->marcarEmailVerificado($existente);
                    $id = $existente;
                } else {
                    $id = $this->oauthRepo->criarUsuario($dados['nome'], $dados['email']);
                }

                // 3) Liga o usuário à conta Google
                $this->oauthRepo->criarVinculo($id, 'Google', $dados['sub'], $dados['email']);

                return $id;
            });

            $usuario = $this->oauthRepo->buscarDadosUsuario($idUsuario);

            if (!$usuario) {
                throw new \RuntimeException('Usuário não encontrado após o login.');
            }

            // refresh token (como no auth/login): sem ele o app perderia a sessão em 15 minutos.
            // O app Android chama com OkHttp; o resto é o site.
            require_once __DIR__ . '/../Middleware/Authentication.php';
            require_once __DIR__ . '/../Services/SessionService.php';
            $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $plataforma = stripos($userAgent, 'okhttp') !== false ? 'Android' : 'Site';
            $refreshToken = \criarRefreshToken();
            $idSessao = $this->oauthRepo->criarSessao(
                (string) $usuario['id_usuario'],
                \criarHashRefreshToken($refreshToken),
                $plataforma,
                \sessionDeviceName($input['dispositivo'] ?? null, $plataforma, $userAgent)
            );
            $token = $this->gerarToken($usuario, $idSessao);

        } catch (\Throwable $e) {
            error_log('Erro no login com Google: ' . $e->getMessage());

            $this->responder(500, [
                'success' => false,
                'message' => 'Erro ao processar login com Google',
            ]);
            return;
        }

        $this->responder(200, [
            'success'      => true,
            'token'        => $token,
            'refreshToken' => $refreshToken,
            'user'         => $usuario,
        ]);
    }

    /**
     * Mesmo JWT do auth/loginSite (criarToken está em Middleware/Authentication.php),
     * então o token do Google é idêntico ao do login normal.
     */
    private function gerarToken(array $usuario, int $idSessao): string
    {
        require_once __DIR__ . '/../Middleware/Authentication.php';

        return criarToken($usuario['id_usuario'], $idSessao);
    }

    private function responder(int $status, array $corpo): void
    {
        http_response_code($status);
        echo json_encode($corpo);
    }
}