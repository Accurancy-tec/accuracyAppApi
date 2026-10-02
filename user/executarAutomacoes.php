<?php
require_once __DIR__ . "/../config/cors.php";
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../database/conexao.php";
require_once __DIR__ . "/../service/automacaoCronService.php";

// A rodada pode demorar (Brapi + vários aportes) e o padrão do Apache é 30s.
// ignore_user_abort: se quem chamou desistir, a rodada termina mesmo assim.
set_time_limit(120);
ignore_user_abort(true);

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

    // "sucesso" vem false se algum ativo ou aporte deu erro, assim o GitHub Actions fica vermelho
    echo json_encode(executarAutomacoesCron($conexao));

} catch (Throwable $erro) {

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Erro interno: " . $erro->getMessage()
    ]);
}