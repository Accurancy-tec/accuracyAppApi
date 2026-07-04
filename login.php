<?php 

include "conn.php";

$dados = json_decode(file_get_contents("php://input"), true);


$email = $dados['email_usuario'];
$senha = $dados['senha_usuario'];

$sql = 'SELECT id_usuario, nome_usuario, email_usuario FROM usuarios_info WHERE email_usuario = :email_usuario AND senha_usuario = :senha_usuario';

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':email_usuario', $email);
$stmt->bindParam(':senha_usuario', $senha);
$stmt->execute();

$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if($usuario){
    $id = $usuario['id_usuario'];
    $nome = $usuario['nome_usuario'];
    $email = $usuario['email_usuario'];
    echo json_encode([
        "success" => "true",
        "message" => "Login realizado com sucesso",
        "user"=> [
            "id_usuario" => $usuario["id_usuario"],
            "nome_usuario" => $usuario["nome_usuario"],
            "email_usuario" => $usuario["email_usuario"]
        ]
    ]);
}
else{
    echo json_encode([
        "success" => "false",
        "message" => "Email ou senha incorretos",
    ]);
}
?>