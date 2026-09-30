<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../Authentication/tokenLogin.php";
require_once __DIR__ . "/../../database/conexao.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Método não permitido. Use POST."
    ]);

    exit;
}

$dados = json_decode(file_get_contents("php://input"),true);

$refreshToken = $dados["refresh_token"] ?? "";

if (empty($refreshToken)) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Refresh Token não enviado."
    ]);

    exit;
}

$refreshTokenHash = criarHashRefreshToken(
    $refreshToken
);

$sql = "SELECT id_sessao, id_usuario, expira_em, revogado_em FROM Sessao_Usuario 
WHERE refresh_token_hash = :refresh_token_hash LIMIT 1";

$stmt = $conexao->prepare($sql);

$stmt->bindParam(":refresh_token_hash",$refreshTokenHash);

$stmt->execute();

$sessao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sessao) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Refresh Token inválido."
    ]);

    exit;
}

if ($sessao["revogado_em"] !== null) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Sessão revogada."
    ]);

    exit;
}

if (strtotime($sessao["expira_em"]) <= time()) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Refresh Token expirado."
    ]);

    exit;
}

$novoAccessToken = criarToken(
    $sessao["id_usuario"]
);


echo json_encode([
    "success" => true,
    "message" => "Token renovado com sucesso",
    "token" => $novoAccessToken
]);