<?php 

include 'conexao.php';

$dados = json_decode(file_get_contents("php://input"), true);

$nome = $dados['nome_usuario'];
$email = $dados['email_usuario'];
$senha = $dados['senha_usuario'];
$cpf = $dados['cpf_usuario'];
$telefone = $dados['telefone_usuario'];

$sql = "INSERT INTO usuarios_info (nome_usuario, email_usuario, senha_usuario, cpf_usuario, telefone_usuario) values (:nome_usuario, :email_usuario, :senha_usuario, :cpf_usuario, :telefone_usuario)";

$stmt = $conexao->prepare($sql);

$stmt->bindParam(':nome_usuario', $nome);
$stmt->bindParam(':email_usuario', $email);
$stmt->bindParam(':senha_usuario', $senha);
$stmt->bindParam(':cpf_usuario', $cpf);
$stmt->bindParam(':telefone_usuario', $telefone);


$stmt->execute();

echo json_encode(["status" => "sucesso"]);
?>