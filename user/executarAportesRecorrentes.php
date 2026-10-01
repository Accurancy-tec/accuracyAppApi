<?php
require_once __DIR__ . "/../config/cors.php";
header("Content-Type: application/json; charset=UTF-8");

require_once "../database/conexao.php";
require_once "../service/automacaoService.php";

try {

    $cronKey = $_SERVER["HTTP_X_CRON_KEY"] ?? "";

    if (
        empty($_ENV["CRON_SECRET"]) ||
        !hash_equals($_ENV["CRON_SECRET"], $cronKey)
    ) {
        http_response_code(401);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Chave do cron inválida"
        ]);

        exit;
    }


    if ($_SERVER["REQUEST_METHOD"] !== "POST") {

        http_response_code(405);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Método não permitido"
        ]);

        exit;
    }


    $resultado = executarAportesRecorrentes($conexao);


    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Aportes recorrentes processados",
        "resultado" => $resultado
    ]);


} catch (Throwable $erro) {

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Erro interno: " . $erro->getMessage()
    ]);
}