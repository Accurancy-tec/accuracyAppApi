<?php

require_once '../database/conexao.php';
require_once '../config/email.php';

$dados = json_decode(file_get_contents("php://input"), true);

$nome = $dados['nome_usuario'];
$email = $dados['email_usuario'];
$senha = $dados['senha_usuario'];
$cpf = $dados['cpf_usuario'];
$telefone = $dados['telefone_usuario'];

if (
    empty($nome) ||
    empty($email) ||
    empty($senha) ||
    empty($cpf) ||
    empty($telefone)
) {
    echo json_encode([
        "status" => "erro",
        "mensagem" => "Todos os campos são obrigatórios."
    ]);
    exit;
}
$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

$sql = "INSERT INTO usuario (nome_usuario, email_usuario, senha_usuario, cpf_usuario, telefone_usuario, email_verificado_usuario) 
VALUES (:nome_usuario, :email_usuario, :senha_usuario, :cpf_usuario, :telefone_usuario, FALSE) 
RETURNING id_usuario";

$stmt = $conexao->prepare($sql);

$stmt->bindParam(':nome_usuario', $nome);
$stmt->bindParam(':email_usuario', $email);
$stmt->bindParam(':senha_usuario', $senhaHash);
$stmt->bindParam(':cpf_usuario', $cpf);
$stmt->bindParam(':telefone_usuario', $telefone);


$stmt->execute();

$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

$idUsuario = $usuario['id_usuario'];

$codigo = str_pad(
    random_int(0, 999999),
    6,
    "0",
    STR_PAD_LEFT
);

$codigoHash = password_hash(
    $codigo,
    PASSWORD_DEFAULT
);

$expiraEm = date(
    "Y-m-d H:i:s",
    time() + (10 * 60)
);

$sqlToken = "INSERT INTO token_usuario
             (id_usuario, tipo_token, token_hash, expira_em)
             VALUES
             (:id_usuario, 'verificacao_email', :token_hash, :expira_em)";

$stmtToken = $conexao->prepare($sqlToken);

$stmtToken->bindParam(
    ':id_usuario',
    $idUsuario,
    PDO::PARAM_INT
);

$stmtToken->bindParam(
    ':token_hash',
    $codigoHash
);

$stmtToken->bindParam(
    ':expira_em',
    $expiraEm
);

$stmtToken->execute();

$enviado = enviarCodigo($email, $codigo);

if (!$enviado) {
    echo json_encode([
        "status" => "erro",
        "mensagem" => "Não foi possível enviar o código de verificação."
    ]);
    exit;
}

echo json_encode([
    "status" => "sucesso",
    "mensagem" => "Cadastro realizado. Código de verificação enviado.",
    "id_usuario" => $idUsuario
]);
?>
