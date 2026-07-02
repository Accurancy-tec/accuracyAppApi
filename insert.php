<?php
//Garante que a resposta vai vim em JSON
header("Content-Type: application/json; charset=UTF-8");
//Puxa o banco de dados
require "conexao.php";

//Verifica se o método foi POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

//Descodifica o JSON
$dados = json_decode(file_get_contents("php://input"), true);

//Atribui os dados
    $ativo = $dados['Ativo'];
    $preco = $dados['Preco'];
    $tipo = $dados['Tipo'];
    $recorrencia = $dados['Recorrencia'];

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

} else {
    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Método inválido"
    ]);
}
?>