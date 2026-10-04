<?php

require_once __DIR__ . "/../../vendor/autoload.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeload();

function autenticar()
{
    $headers = getallheaders();

    $authHeader = $headers["Authorization"] ?? '';

    if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {

        http_response_code(401);

        echo json_encode([
            "erro" => "Token não enviado"
        ]);

        exit;
    }

    try {

        $decoded = JWT::decode(
            $matches[1],
            new Key($_ENV["SECRET_KEY"], "HS256")
        );

        return $decoded->sub;

    } catch (Exception $ex) {

        http_response_code(401);

        echo json_encode([
            "erro" => "Token inválido ou expirado","detalhe" => $ex->getMessage()
        ]);

        exit;
    }
}

function criarToken($idUsuario)
{
    $payload = [
        "iss" => "accuracy-mob-api",
        "iat" => time(),
        "exp" => time() + (60 * 15),
        "sub" => $idUsuario
    ];

    return JWT::encode(
        $payload,
        $_ENV["SECRET_KEY"],
        "HS256"
    );
}

function validarToken($token)
{
    try {

        $decoded = JWT::decode(
            $token,
            new Key($_ENV["SECRET_KEY"], "HS256")
        );

        // Verifica quem criou o token
        if (($decoded->iss ?? "") !== "accuracy-mob-api") {
            return false;
        }

        return $decoded;

    } catch (Exception $erro) {

        return false;

    }
}

function criarRefreshToken()
{
    return bin2hex(random_bytes(64));
}

function criarHashRefreshToken(String $refreshToken)
{
    return hash("sha256", $refreshToken);
}