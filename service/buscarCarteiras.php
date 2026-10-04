<?php 
    header('Content-Type: application/json; charset=utf-8');

    require_once __DIR__ . "/../database/conexao.php";
    require_once __DIR__ . "/../Authentication/Authentication.php";

    try{
        $id_usuario = autenticar();

        $sql = $conexao->prepare("SELECT id_carteira, nome_carteira, tipo_carteira, saldo_livre_carteira FROM carteira WHERE id_usuario = :id_usuario ORDER BY id_carteira DESC");

        $sql->execute([":id_usuario" => $id_usuario]);

        $resultado = $sql->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "message" => "Carteiras encontradas",
            "carteiras" => $resultado
        ]);
    }
    catch(PDOException $e){
        echo json_encode([
            "success" => false,
            "message" => "Não foi possível buscar as carterias" . $e->getMessage()
        ]);
    }
?>