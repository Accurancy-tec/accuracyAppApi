<?php
 
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Repositories/HistoricoaportesRepository.php";
 
// Devolve todos os aportes do usuário logado (com data_aporte, id_carteira e observação).
function buscarHistoricoAportes()
{
    global $conexao;
 
    try {
        $idUsuario = autenticar();
 
        $aportes = listarHistoricoAportesUsuario($conexao, $idUsuario);
 
        echo json_encode([
            "sucesso" => true,
            "aportes" => $aportes
        ]);
    } catch (Throwable $erro) {
        http_response_code(500);
 
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao buscar histórico de aportes: " . $erro->getMessage()
        ]);
    }
}