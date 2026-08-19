<?php
require "vendor/autoload.php";
require_once "config/config.php";

use Firebase\JWT\JWT;

function criarToken($idUsuario)
{
$secretKey = getenv(secretKey);

$payload =[
    "iss" => "accuracy-mob-api",
    "iat" => time(),
    "exp" => time() + (60 * 120),
    "sub" => $idUsuario
];

$token = JWT::encode($payload,$secretKey,'HS256');
}
?>