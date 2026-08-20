<?php
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/config.php";

use Firebase\JWT\JWT;

function criarToken($idUsuario){


$payload =[
    "iss" => "accuracy-mob-api",
    "iat" => time(),
    "exp" => time() + (60 * 120),
    "sub" => $idUsuario
];

return JWT::encode($payload,secretKey,'HS256');

}
?>