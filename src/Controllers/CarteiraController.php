<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php";
require "../config/cors.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/CarteiraService.php";

function criarCarteira(){
    global $conexao;

    $dados = json_decode(file_get_contents("php://input"), true);

    $idUser = autenticar();
    $nameWallet = $dados['nome_carteira'];
    $typeWallet = $dados['tipo_carteira'];
    $availableWalletBalance = $dados['saldo_livre_carteira'];
    

    try {
        criarCarteiraService($conexao, $idUser, $nameWallet, $typeWallet, $availableWalletBalance);
            echo json_encode([
                "sucesso" => true,
                "mensagem" => "carteira cadastrado com sucesso"
            ]);

    } catch (PDOException $erro) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }
}

function buscarCarteiras(){
    global $conexao;

    try{
        $id_usuario = autenticar();

        $resultado = getCarteirasDoUsuarioService($conexao, $id_usuario);

        echo json_encode([
            "success" => true,
            "message" => "Carteiras encontradas",
            "carteiras" => $resultado
        ]);
    }
    catch(PDOException $e){
        echo json_encode([
            "success" => false,
            "message" => "Não foi possível buscar as carteiras" . $e->getMessage()
        ]);
    }
}

function itemInvestimento(){
    global $conexao;

    autenticar();

    $idCarteira = $_GET['id_carteira'] ?? null;

    if (!$idCarteira) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "ID da carteira não fornecido."
        ]);
        exit;
    }

    try {
        $posicoes = buscarPosicoesService($conexao, $idCarteira);
            echo json_encode([
                "sucesso" => true,
                "posicoes" => $posicoes
            ]);
    } 
    catch (PDOException $erro) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }
}

?>