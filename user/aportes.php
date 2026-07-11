<?php
//Garante que a resposta vai vim em JSON
header("Content-Type: application/json; charset=UTF-8");
//Puxa o banco de dados
require "../database/conexao.php"; // Modifiquei um pouco o caminho pra evitar erro no android studio

//Descodifica o JSON
$dados = json_decode(file_get_contents("php://input"), true);

//Atribui os dados
    $ativo = $dados['ativo_aporte'];
    $preco = $dados['preco_aporte'];
    $tipo = $dados['tipo_aporte'];
    $recorrencia = $dados['recorrencia_aporte'];

try {
    //Comando de insert
    $sql = "INSERT INTO tb_aporte (ativo_aporte, preco_aporte, tipo_aporte, recorrencia_aporte) VALUES (?, ?, ?, ?)";
    //Parâmetros para evitar SQLinjection
        $insert = $conexao->prepare($sql);
        $insert->bindParam(1, $ativo);
        $insert->bindParam(2, $preco);
        $insert->bindParam(3, $tipo);
        $insert->bindParam(4, $recorrencia);
        $insert->execute();

        echo json_encode([
            "sucesso" => true,
            "mensagem" => "Aporte cadastrado com sucesso"
        ]);

} catch (PDOException $erro) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }


 
    


?>