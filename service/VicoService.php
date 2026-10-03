<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../Authentication/Authentication.php";
require_once __DIR__ . "/../database/conexao.php";

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

    $resposta = $sql->fetchAll(PDO::FETCH_ASSOC);

    $dados = [];

    foreach ($resposta as $row) {
        $dados[] = [
            "ativo_aporte" => $row["ativo_aporte"],
            "preco_aporte" => (float) $row["preco_aporte"]
        ];
    }

    echo json_encode([
        "dados" => $dados
    ]);

} catch (Throwable $e) {

    echo json_encode([
        "dados" => [],
        "erro" => $e->getMessage()
    ]);
}