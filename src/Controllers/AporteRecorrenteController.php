<?php

header("Content-Type: application/json; charset=UTF-8");

require_once "../config/cors.php";
require_once "../database/conexao.php";
require_once "../accuracyApi/src/Middleware/Authentication.php";
require_once "../accuracyApi/src/Repositories/AporteRepository.php";

try {

    $idUsuario = autenticar();

    $dados = json_decode(file_get_contents("php://input"), true);

    $acao = $dados["acao"] ?? null;

    switch ($acao) {

        case "criar":

            $idCarteira = $dados["id_carteira"] ?? null;
            $idAtivo = $dados["id_ativo"] ?? null;
            $valorRecorrente = $dados["valor_recorrente"] ?? null;
            $frequenciaRecorrente = $dados["frequencia_recorrente"] ?? null;
            $diaReferenciaRecorrente = $dados["dia_referencia_recorrente"] ?? null;
            $proximaExecucaoRecorrente = $dados["proxima_execucao_recorrente"] ?? null;

            if (
                !$idCarteira ||
                !$idAtivo ||
                $valorRecorrente === null ||
                !$frequenciaRecorrente ||
                !$proximaExecucaoRecorrente
            ) {
                http_response_code(400);

                echo json_encode([
                    "sucesso" => false,
                    "mensagem" => "Dados obrigatórios não informados"
                ]);

                exit;
            }

            if (
                !carteiraPertenceAoUsuario(
                    $conexao,
                    $idCarteira,
                    $idUsuario
                )
            ) {
                http_response_code(403);

                echo json_encode([
                    "sucesso" => false,
                    "mensagem" => "A carteira não pertence ao usuário"
                ]);

                exit;
            }

            $id = criarAporteRecorrente(
                $conexao,
                $idCarteira,
                $idAtivo,
                $valorRecorrente,
                $frequenciaRecorrente,
                $diaReferenciaRecorrente,
                $proximaExecucaoRecorrente
            );

            echo json_encode([
                "sucesso" => true,
                "mensagem" => "Aporte recorrente criado com sucesso",
                "id_aporte_recorrente" => $id
            ]);

            break;


        case "listar":

            $idCarteira = $dados["id_carteira"] ?? null;

            if (!$idCarteira) {
                http_response_code(400);

                echo json_encode([
                    "sucesso" => false,
                    "mensagem" => "id_carteira é obrigatório"
                ]);

                exit;
            }

            if (
                !carteiraPertenceAoUsuario(
                    $conexao,
                    $idCarteira,
                    $idUsuario
                )
            ) {
                http_response_code(403);

                echo json_encode([
                    "sucesso" => false,
                    "mensagem" => "A carteira não pertence ao usuário"
                ]);

                exit;
            }

            $aportes = listarAporteRecorrente(
                $conexao,
                $idCarteira
            );

            echo json_encode([
                "sucesso" => true,
                "aportes" => $aportes
            ]);

            break;


        case "atualizar":

            $idAporteRecorrente =
                $dados["id_aporte_recorrente"] ?? null;

            $valorRecorrente =
                $dados["valor_recorrente"] ?? null;

            $frequenciaRecorrente =
                $dados["frequencia_recorrente"] ?? null;

            $diaReferenciaRecorrente =
                $dados["dia_referencia_recorrente"] ?? null;

            $ativoFlagRecorrente =
                $dados["ativo_flag_recorrente"] ?? null;


            if (
                !$idAporteRecorrente ||
                $valorRecorrente === null ||
                !$frequenciaRecorrente ||
                $ativoFlagRecorrente === null
            ) {
                http_response_code(400);

                echo json_encode([
                    "sucesso" => false,
                    "mensagem" => "Dados obrigatórios não informados"
                ]);

                exit;
            }


            $sql = "
                SELECT id_carteira
                FROM aporte_recorrente
                WHERE id_aporte_recorrente = ?
            ";

            $stmt = $conexao->prepare($sql);
            $stmt->execute([$idAporteRecorrente]);

            $aporte = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$aporte) {

                http_response_code(404);

                echo json_encode([
                    "sucesso" => false,
                    "mensagem" => "Aporte recorrente não encontrado"
                ]);

                exit;
            }


            if (
                !carteiraPertenceAoUsuario(
                    $conexao,
                    $aporte["id_carteira"],
                    $idUsuario
                )
            ) {
                http_response_code(403);

                echo json_encode([
                    "sucesso" => false,
                    "mensagem" =>
                        "Aporte recorrente não pertence ao usuário"
                ]);

                exit;
            }


            $resultado = atualizarAporteRecorrente(
                $conexao,
                $idAporteRecorrente,
                $valorRecorrente,
                $frequenciaRecorrente,
                $diaReferenciaRecorrente,
                $ativoFlagRecorrente
            );


            echo json_encode([
                "sucesso" => $resultado,
                "mensagem" => $resultado
                    ? "Aporte recorrente atualizado com sucesso"
                    : "Nenhuma alteração realizada"
            ]);

            break;


        default:

            http_response_code(400);

            echo json_encode([
                "sucesso" => false,
                "mensagem" =>
                    "Ação inválida. Use criar, listar ou atualizar"
            ]);
    }

} catch (Throwable $erro) {

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Erro interno: " . $erro->getMessage()
    ]);
}