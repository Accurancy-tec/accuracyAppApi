<?php

namespace App\Controllers;

use App\Repositories\OAuthRepository;
use App\Services\LinkedinAuthService;

/**
 * ARQUIVO NOVO. Mesmo fluxo do GoogleAuthController, mas com o "code" do LinkedIn.
 */
class LinkedinAuthController
{
    public function __construct(
        private LinkedinAuthService $linkedinService,
        private OAuthRepository $oauthRepo
    ) {}

    public function loginLinkedin(): void
    {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $code  = $input['code'] ?? '';

        if (!$code) {
            $this->responder(400, ['success' => false, 'message' => 'code ausente']);
            return;
        }

        $dados = $this->linkedinService->validarCode($code);

        if (!$dados) {
            $this->responder(401, ['success' => false, 'message' => 'Login com LinkedIn inválido']);
            return;
        }

        try {
            $idUsuario = $this->oauthRepo->transacao(function () use ($dados) {

                $id = $this->oauthRepo->buscarUsuarioPorVinculo('LinkedIn', $dados['sub']);

                if ($id !== null) {
                    return $id;
                }

                $id = $this->oauthRepo->buscarUsuarioPorEmail($dados['email'])
                    ?? $this->oauthRepo->criarUsuario($dados['nome'], $dados['email']);

                $this->oauthRepo->criarVinculo($id, 'LinkedIn', $dados['sub'], $dados['email']);

                return $id;
            });

            $usuario = $this->oauthRepo->buscarDadosUsuario($idUsuario);

            if (!$usuario) {
                throw new \RuntimeException('Usuário não encontrado após o login.');
            }

            // criarToken() é a mesma função que o loginSite usa
            $token = criarToken($usuario['id_usuario']);

        } catch (\Throwable $e) {
            error_log('Erro no login com LinkedIn: ' . $e->getMessage());
            $this->responder(500, ['success' => false, 'message' => 'Erro ao processar login com LinkedIn']);
            return;
        }

        $this->responder(200, [
            'success' => true,
            'token'   => $token,
            'user'    => $usuario,
        ]);
    }

    private function responder(int $status, array $corpo): void
    {
        http_response_code($status);
        echo json_encode($corpo);
    }
}