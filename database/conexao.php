<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

try {

    $dsn = "pgsql:host=" . $_ENV["DB_HOST"] .
           ";port=" . $_ENV["DB_PORT"] .
           ";dbname=" . $_ENV["DB_NAME"];

    $conexao = new PDO(
        $dsn,
        $_ENV["DB_USER"],
        $_ENV["DB_PASSWORD"]
    );

    $conexao->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

} catch (PDOException $erro) {

    echo json_encode([
        "success" => false,
        "message" => "Erro de conexão com o banco"
    ]);

    exit;
}

return $conexao;