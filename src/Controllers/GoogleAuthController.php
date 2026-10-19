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
                $id = $this->oauthRepo->buscarUsuarioPorEmail($dados['email'])
                    ?? $this->oauthRepo->criarUsuario($dados['nome'], $dados['email']);

                // 3) Liga o usuário à conta Google
                $this->oauthRepo->criarVinculo($id, 'Google', $dados['sub'], $dados['email']);

                return $id;
            });

            $usuario = $this->oauthRepo->buscarDadosUsuario($idUsuario);

            if (!$usuario) {
                throw new \RuntimeException('Usuário não encontrado após o login.');
            }

            $token = $this->gerarToken($usuario);

        } catch (\Throwable $e) {
            error_log('Erro no login com Google: ' . $e->getMessage());

            $this->responder(500, [
                'success' => false,
                'message' => 'Erro ao processar login com Google',
            ]);
            return;
        }

        $this->responder(200, [
            'success' => true,
            'token'   => $token,
            'user'    => $usuario,
        ]);
    }

    /**
     * PREENCHER: use aqui o MESMO código que o auth/loginSite usa
     * para gerar o JWT, de forma que o token do Google seja igual
     * ao do login normal.
     *
     * $usuario traz: id_usuario, nome_usuario, email_usuario.
     */
    private function gerarToken(array $usuario): string
    {
        throw new \RuntimeException(
            'Falta preencher GoogleAuthController::gerarToken() com a geração de JWT do loginSite.'
        );
    }

    private function responder(int $status, array $corpo): void
    {
        http_response_code($status);
        echo json_encode($corpo);
    }
}