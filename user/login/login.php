<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../Authentication/tokenLogin.php";
require_once __DIR__ . "/../../database/conexao.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode([
        "success" => false,
        "message" => "Método não permitido. Use POST."
    ]);
    exit;
}

$dados = json_decode(file_get_contents("php://input"), true);

if (!$dados) {
    echo json_encode([
        "success" => false,
        "message" => "Nenhum dado foi enviado."
    ]);
    exit;
}

$email = $dados["email_usuario"] ?? "";
$senha = $dados["senha_usuario"] ?? "";

if (empty($email) || empty($senha)) {
    echo json_encode([
        "success" => false,
        "message" => "E-mail e senha são obrigatórios."
    ]);
    exit;
}

$sql = "SELECT id_usuario, nome_usuario, email_usuario, senha_usuario FROM usuario WHERE email_usuario = :email_usuario LIMIT 1";

$stmt = $conexao->prepare($sql);
$stmt->bindParam(":email_usuario", $email);
$stmt->execute();

$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    echo json_encode([
        "success" => false,
        "message" => "Email ou senha incorretos"
    ]);
    exit;
}

/*if (!password_verify($senha, $usuario["senha_usuario"])) {
    echo json_encode([
        "success" => false,
        "message" => "Email ou senha incorretos"
    ]);
    exit;
}*/

if ($senha !== $usuario["senha_usuario"]) {
    echo json_encode([
        "success" => false,
        "message" => "Email ou senha incorretos"
    ]);
    exit;
}

/*if (!$usuario["email_verificado_usuario"]) {
    echo json_encode([
        "success" => false,
        "message" => "E-mail ainda não foi verificado."
    ]);
    exit;
}*/

$accessToken = criarToken(
    $usuario["id_usuario"]
);

$refreshToken = criarRefreshToken();

$refreshTokenHash = criarHashRefreshToken($refreshToken);

$expiraEm = date(
    "Y-m-d H:i:s",
    time() + (60 * 60 * 24 * 30)
);


$sqlSessao = "INSERT INTO Sessao_Usuario (id_usuario,refresh_token_hash,dispositivo_sessao,expira_em) VALUES 
(:id_usuario,:refresh_token_hash,:dispositivo_sessao,:expira_em)";

$stmtSessao = $conexao->prepare($sqlSessao);

$stmtSessao->bindParam(":id_usuario", $usuario["id_usuario"],);
$stmtSessao->bindParam(":refresh_token_hash",$refreshTokenHash);
$stmtSessao->bindValue(":dispositivo_sessao","Android Accuracy");
$stmtSessao->bindParam(":expira_em",$expiraEm);

$stmtSessao->execute();

echo json_encode([
    "success" => true,
    "message" => "Login realizado com sucesso",
    "token" => $accessToken,
    "refreshToken" => $refreshToken,
    "user" => [
        "id_usuario" => $usuario["id_usuario"],
        "nome_usuario" => $usuario["nome_usuario"],
        "email_usuario" => $usuario["email_usuario"]
    ]
]);