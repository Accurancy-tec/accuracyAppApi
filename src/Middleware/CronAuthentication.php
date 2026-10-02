<?php 
    function autenticarCron($cronKey){

        if (empty($_ENV["CRON_SECRET"]) || !hash_equals($_ENV["CRON_SECRET"], $cronKey)) {
        http_response_code(401);
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Chave do cron inválida"
        ]);
        exit;
    }
    }
?>