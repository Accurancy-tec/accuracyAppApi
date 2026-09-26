<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../Authentication/Authentication.php";
require_once __DIR__ . "/../database/conexao.php";

<<<<<<< HEAD
$id = autenticar();
   $sql = $conexao->prepare("SELECT * from tb_aporte where id_usuario = ? order by id_aporte limit 4");
    $sql->bindParam(1, $id);
    $sql->execute();
=======
try {
    $id_usuario = autenticar();

    $sql = $conexao->prepare("
        SELECT
            at.simbolo_ativo AS ativo_aporte,
            a.valor_aporte AS preco_aporte
        FROM Aporte a
        INNER JOIN Carteira c
            ON c.id_carteira = a.id_carteira
        INNER JOIN Ativo at
            ON at.id_ativo = a.id_ativo
        WHERE c.id_usuario = ?
        ORDER BY a.id_aporte ASC
        LIMIT 4
    ");

    $sql->execute([$id_usuario]);
>>>>>>> feec4a7a48048cbfbbd5a0f980ec57b82e85c37c

    $resposta = $sql->fetchAll(PDO::FETCH_ASSOC);

    $dados = [];

<<<<<<< HEAD
foreach($resposta as $row){
    $dados[] = [
        "ativo_aporte" => $row["ativo_aporte"],
        "preco_aporte" => (float)$row["preco_aporte"]
    ];
}
=======
    foreach ($resposta as $row) {
        $dados[] = [
            "ativo_aporte" => $row["ativo_aporte"],
            "preco_aporte" => (float) $row["preco_aporte"]
        ];
    }
>>>>>>> feec4a7a48048cbfbbd5a0f980ec57b82e85c37c

    echo json_encode([
        "dados" => $dados
    ]);

} catch (Throwable $e) {

    echo json_encode([
        "dados" => [],
        "erro" => $e->getMessage()
    ]);
}