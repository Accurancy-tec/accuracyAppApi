<?php 

include 'conn.php';

$dados = json_decode(file_get_contents("php://input"), true);

$nome = $dados['nome_usuario'];
$email = $dados['email_usuario'];

$sql = "INSERT INTO usuarios_info (nome_usuario, email_usuario) values (:nome_usuario, :email_usuario)";

$stmt = $pdo->prepare($sql);

$stmt->bindParam(':nome_usuario', $nome);
$stmt->bindParam(':email_usuario', $email);

$stmt->execute();

echo json_encode(["status" => "sucesso"]);
?>