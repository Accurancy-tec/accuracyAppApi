<?php
// CONTROLLER = recebe o pedido do site e devolve a resposta em JSON.

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../Services/CursoService.php";

// GET /cursos/trilhas
function listarTrilhasController()
{
    global $conexao;

    try {
        $trilhas = listarTrilhasService($conexao);

        echo json_encode([
            "success" => true,
            "trilhas" => $trilhas
        ]);
    } catch (PDOException $erro) {
        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Não foi possível buscar as trilhas"
        ]);
    }
}

// GET /cursos/trilha?id_trilha=1
function buscarTrilhaController()
{
    global $conexao;

    $idTrilha = $_GET["id_trilha"] ?? null;

    if (!$idTrilha || !ctype_digit((string) $idTrilha)) {
        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "ID da trilha inválido"
        ]);
        return;
    }

    try {
        $trilha = buscarTrilhaService($conexao, (int) $idTrilha);

        if (!$trilha) {
            http_response_code(404);

            echo json_encode([
                "success" => false,
                "message" => "Trilha não encontrada"
            ]);
            return;
        }

        echo json_encode([
            "success" => true,
            "trilha" => $trilha
        ]);
    } catch (PDOException $erro) {
        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Não foi possível buscar a trilha"
        ]);
    }
}
