<?php

try {

    $dsn = "pgsql:host=" . getenv("DB_HOST") .
           ";port=" . getenv("DB_PORT") .
           ";dbname=" . getenv("DB_NAME");

    $conexao = new PDO($dsn, getenv("DB_USER"), getenv("DB_PASSWORD"));

    $conexao->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION
    );

} catch (PDOException $erro) {

    echo json_encode([
        "success" => false,
        "message" => "Erro de conexão com o banco",
        "error" => $erro->getMessage()
    ]);

    exit;
}