<?php 
header("Content-Type: application/json; charset=utf-8");

    require_once __DIR__ . "/../database/conexao.php";
    require_once __DIR__ . "/../src/Middleware/Authentication.php";

    try{
        $id_usuario = autenticar();
        if($_SERVER["REQUEST_METHOD"] !== "POST"){
            echo json_encode([
                "success" => false,
                "message" => "Método não permitido. Use Post"
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

        $nome = $dados["nome_carteira"];
        $tipo = $dados["tipo_carteira"];

        $sql = $conexao->prepare("INSERT INTO carteira (id_usuario, nome_carteira, tipo_carteira) VALUES (:id_usuario, :nome_carteira, :tipo_carteira) ");

        $sql->execute([$id_usuario, $nome, $tipo]); 

        echo json_encode([
            "success" => true,
            "message" => "Carteira criada com sucesso."
        ]);

    }
    catch(PDOException $e){
        echo json_encode([
            "success" => false,
            "message" => "Erro ao criar carteira",
            "erro" => $e->getMessage()
        ]);
    }
?>