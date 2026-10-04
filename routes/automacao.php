<?php

require_once __DIR__ . "/../src/Controllers/AutomacaoController.php";

$acao = $partes[1] ?? null;

switch ($acao) {

    case "realizar-aporte-recorrente":
        realizarAportesRecorrentes();
        break;

    case "executar-automacoes":
        executarAutomacoes();
        break;

    default:
        http_response_code(404);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Rota de automação não encontrada"
        ]);
        break;
}
?>