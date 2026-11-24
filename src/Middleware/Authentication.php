<?php

require_once __DIR__ . "/../../vendor/autoload.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

function obterHeaderAutorizacao(): string
{
    $valores = [];

    foreach (["HTTP_AUTHORIZATION", "REDIRECT_HTTP_AUTHORIZATION", "Authorization"] as $chave) {
        if (isset($_SERVER[$chave]) && is_string($_SERVER[$chave])) {
            $valores[] = $_SERVER[$chave];
        }
    }

    foreach (["getallheaders", "apache_request_headers"] as $funcao) {
        if (!function_exists($funcao)) {
            continue;
        }

        $headers = $funcao();
        if (!is_array($headers)) {
            continue;
        }

        foreach ($headers as $nome => $valor) {
            if (is_string($nome) && strtolower($nome) === "authorization" && is_string($valor)) {
                $valores[] = $valor;
            }
        }
    }

    foreach ($valores as $valor) {
        $valor = trim($valor);
        if (preg_match('/^Bearer\s+\S+$/i', $valor)) {
            return $valor;
        }
    }

    return "";
}

function autenticar()
{
    $authHeader = obterHeaderAutorizacao();

    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authHeader), $matches)) {
        http_response_code(401);
        echo json_encode(["erro" => "Token não enviado"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $secretKey = $_ENV["SECRET_KEY"] ?? getenv("SECRET_KEY");
    if (!is_string($secretKey) || trim($secretKey) === "") {
        error_log("Authentication: SECRET_KEY não está configurada.");
        http_response_code(500);
        echo json_encode(["erro" => "Configuração de autenticação indisponível."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $decoded = JWT::decode(
            $matches[1],
            new Key($secretKey, "HS256")
        );

        if (!isset($decoded->sub)) {
            throw new UnexpectedValueException("Token sem identificador de usuário.");
        }

        return $decoded->sub;
    } catch (Throwable $ex) {
        http_response_code(401);
        echo json_encode([
            "erro" => "Token inválido ou expirado",
            "detalhe" => $ex->getMessage()
        ], JSON_UNESCAPED_UNICODE);
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
