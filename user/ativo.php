<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php"; 


$dados = json_decode(file_get_contents("php://input"), true);

    $simboloAtivo = $dados['simbolo_ativo'];
    $nomeAtivo = $dados['nome_ativo'];
    $categoriaAtivo = $dados['categoria_ativo'];

try {

    $sql = "INSERT INTO ativo (simbolo_ativo, nome_ativo, categoria_ativo) VALUES (?, ?, ?)";
        $insert = $conexao->prepare($sql);
        $insert->bindParam(1, $simboloAtivo);
        $insert->bindParam(2, $nomeAtivo);
        $insert->bindParam(3, $categoriaAtivo);
        $insert->execute();

        echo json_encode([
            "sucesso" => true,
            "mensagem" => "ativo cadastrado com sucesso"
        ]);

} catch (PDOException $erro) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }
?>