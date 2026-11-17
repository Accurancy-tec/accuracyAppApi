<?php

namespace App\Services;

use Google\Client;

class GoogleAuthService
{
    /**
     * Valida o id_token recebido do site/app.
     * Retorna ['sub', 'email', 'nome'] ou null se o token for inválido.
     */
    public function validarIdToken(string $idToken): ?array
    {
        foreach ($this->clientIds() as $clientId) {

            try {
                $client  = new Client(['client_id' => $clientId]);
                $payload = $client->verifyIdToken($idToken);
            } catch (\Throwable $e) {
                // token malformado ou erro ao buscar as chaves do Google
                continue;
            }

            if ($payload && !empty($payload['email_verified'])) {
                return [
                    'sub'   => $payload['sub'],
                    'email' => $payload['email'],
                    'nome'  => $payload['name'] ?? '',
                ];
            }
        }

        return null;
    }

    /** IDs de cliente aceitos (site e, no futuro, app). */
    private function clientIds(): array
    {
        return array_values(array_filter([
            $_ENV['GOOGLE_CLIENT_ID_WEB']     ?? getenv('GOOGLE_CLIENT_ID_WEB')     ?: null,
            $_ENV['GOOGLE_CLIENT_ID_ANDROID'] ?? getenv('GOOGLE_CLIENT_ID_ANDROID') ?: null,
        ]));
    }
}