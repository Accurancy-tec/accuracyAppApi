<?php
//Garante que a resposta vai vim em JSON
header("Content-Type: application/json; charset=UTF-8");
//Puxa o banco de dados
require "../database/conexao.php"; // Caso no android de um erro de 

//Verifica se o método foi POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

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

} else if($_SERVER["REQUEST_METHOD"] == "GET"){

    $sql = $conexao->prepare("SELECT * from tb_aporte order by id_aporte limit 4");
    $sql->execute();
    
    $resposta = $sql->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["sucesso" => true, "ativos" => $resposta]);
} else {
    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Método inválido"
    ]);
}
?>