<?php

require_once __DIR__ . "/../vendor/autoload.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;


// Carrega o .env
$dotenv = Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();

function criarToken($idUsuario)
{
    $payload = [

        "iss" => "accuracy-mob-api",

        "iat" => time(),

        "exp" => time() + (60 * 120),

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