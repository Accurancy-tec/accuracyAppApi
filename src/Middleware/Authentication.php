<?php

require_once __DIR__ . "/../../vendor/autoload.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

function autenticar()
{
    // O cors.php já recompõe HTTP_AUTHORIZATION; getallheaders() fica como
    // reserva, sem diferenciar maiúsculas de minúsculas ("authorization").
    $authHeader = $_SERVER["HTTP_AUTHORIZATION"] ?? '';

    if ($authHeader === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $nome => $valor) {
            if (strtolower($nome) === 'authorization') {
                $authHeader = $valor;
                break;
            }
        }
    }

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

        // Tokens com "sid" pertencem a uma sessão: se o usuário desconectou esse aparelho,
        // o token deixa de valer na hora (sem esperar os 15 minutos de validade).
        $idSessao = isset($decoded->sid) ? (int) $decoded->sid : null;
        $GLOBALS["sessao_atual"] = $idSessao;
        if ($idSessao && !sessaoDoTokenAtiva($idSessao, (int) $decoded->sub)) {
            http_response_code(401);
            echo json_encode([
                "erro" => "Sessão encerrada neste aparelho. Entre de novo.",
                "sessao_encerrada" => true
            ]);
            exit;
        }

        return $decoded->sub;
    } catch (Exception $ex) {

        http_response_code(401);

        echo json_encode([
            "erro" => "Token inválido ou expirado",
            "detalhe" => $ex->getMessage()
        ]);

        exit;
    }
}

/**
 * Confere a sessão do token. Se o banco falhar, não derruba o usuário: o token continua
 * valendo até expirar (15 minutos), como antes desta conferência existir.
 */
function sessaoDoTokenAtiva(int $idSessao, int $idUsuario): bool
{
    $conexao = $GLOBALS["conexao"] ?? null;
    if (!$conexao instanceof PDO) {
        return true;
    }
    require_once __DIR__ . "/../Services/SessaoService.php";
    try {
        return sessaoAtiva($conexao, $idSessao, $idUsuario);
    } catch (Throwable $erro) {
        error_log("Falha ao conferir a sessão $idSessao: " . $erro->getMessage());
        return true;
    }
}

/** Token de acesso (15 minutos). Com $idSessao, o token fica ligado ao aparelho que fez login. */
function criarToken($idUsuario, ?int $idSessao = null)
{
    $payload = [
        "iss" => "accuracy-mob-api",
        "iat" => time(),
        "exp" => time() + (60 * 15),
        "sub" => $idUsuario
    ];
    if ($idSessao) {
        $payload["sid"] = $idSessao;
    }

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
