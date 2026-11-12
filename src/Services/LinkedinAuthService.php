<?php

namespace App\Services;

/**
 * ARQUIVO NOVO. Troca o "code" do LinkedIn pelos dados do usuário.
 * Retorna ['sub', 'email', 'nome'] ou null se algo falhar.
 */
class LinkedinAuthService
{
    private const URL_TOKEN    = 'https://www.linkedin.com/oauth/v2/accessToken';
    private const URL_USERINFO = 'https://api.linkedin.com/v2/userinfo';

    public function validarCode(string $code): ?array
    {
        $clientId     = $_ENV['LINKEDIN_CLIENT_ID']     ?? getenv('LINKEDIN_CLIENT_ID');
        $clientSecret = $_ENV['LINKEDIN_CLIENT_SECRET'] ?? getenv('LINKEDIN_CLIENT_SECRET');
        // O redirect_uri vem do servidor (e não do site), para ninguém poder trocá-lo
        $redirectUri  = $_ENV['LINKEDIN_REDIRECT_URI']  ?? getenv('LINKEDIN_REDIRECT_URI');

        if (!$clientId || !$clientSecret || !$redirectUri) {
            error_log('LinkedIn: variáveis de ambiente ausentes');
            return null;
        }

        $token = $this->requisitar(self::URL_TOKEN, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if (empty($token['access_token'])) {
            return null;
        }

        $info = $this->requisitar(self::URL_USERINFO, null, $token['access_token']);

        $emailVerificado = filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (empty($info['sub']) || empty($info['email']) || !$emailVerificado) {
            return null;
        }

        return [
            'sub'   => $info['sub'],
            'email' => strtolower($info['email']),
            'nome'  => $info['name'] ?? '',
        ];
    }

    private function requisitar(string $url, ?array $postCampos, ?string $bearer = null): array
    {
        $ch = curl_init($url);

        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];

        if ($postCampos !== null) {
            $opcoes[CURLOPT_POST]       = true;
            $opcoes[CURLOPT_POSTFIELDS] = http_build_query($postCampos);
        }

        if ($bearer !== null) {
            $opcoes[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $bearer;
        }

        curl_setopt_array($ch, $opcoes);
        $resposta = curl_exec($ch);
        curl_close($ch);

        return json_decode((string) $resposta, true) ?: [];
    }
}