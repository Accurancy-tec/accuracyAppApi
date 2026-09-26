<?php

require_once __DIR__ . "/../vendor/autoload.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

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