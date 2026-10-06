<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php";
require_once __DIR__ . "/../../config/cors.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/PosicaoService.php";
require_once __DIR__ . "/../Services/AtivoService.php";
require_once __DIR__ . "/../Services/AporteService.php";
require_once __DIR__ . "/../Services/CotacaoService.php";
require_once __DIR__ . "/../Services/PosicaoService.php";
require_once __DIR__ . "/../Services/CarteiraService.php";

function buscarAportesDoUsuario()
{
    global $conexao;

    try {
        $id_usuario = autenticar();

        $limite = isset($_GET["limite"]) ? (int) $_GET["limite"] : 200;
        $limite = max(1, min($limite, 200));

        $aportes = listarAportesUsuarioService($conexao, $id_usuario, $limite);

        echo json_encode([
            "sucesso" => true,
            "ativos" => $aportes
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao buscar aportes" . $e->getMessage()
        ]);
    }
}

function fazerAporte()
{
    global $conexao;

    $idUsuario = autenticar();

    $dados = json_decode(file_get_contents("php://input"), true);

    $simboloAtivo = $dados["ativo_aporte"] ?? null;
    $idAtivo = $dados["id_ativo"] ?? null;
    $nomeAtivo = $dados["name_ativo"] ?? null;
    $categoriaAtivo = $dados["categoria_ativo"] ?? null;
    $idWallet = $dados["id_carteira"] ?? null;
    $typeContribution = $dados['tipo_aporte'] ?? null;
    $contributionQuantity = $dados['quantidade_aporte'] ?? null;
    $contributionAmount = $dados['valor_aporte'] ?? null;
    $contributionRecurrence = $dados['recorrencia_aporte'];
    $contributionNotes = $dados['observacao_aporte'] ?? null;
    $contributionDate = date("Y-m-d");

    try {
        if ($idWallet) {
            if (!carteiraPertenceAoUsuarioService($conexao, $idWallet, $idUsuario)) {
                http_response_code(403);
                echo json_encode(["sucesso" => false, "mensagem" => "Carteira inválida."]);
                exit;
            }
        } else {
            // O app ainda não envia id_carteira: usa a carteira mais antiga do
            // usuário (a lista vem do mais novo pro mais antigo) e, se ele não
            // tiver nenhuma, cria a "Carteira principal".
            $carteirasDoUsuario = getCarteirasDoUsuarioService($conexao, $idUsuario);

            $idWallet = $carteirasDoUsuario
                ? end($carteirasDoUsuario)["id_carteira"]
                : criarCarteiraService($conexao, $idUsuario, "Carteira principal", "Real");
        }

        $conexao->beginTransaction();

        $idAtivo = buscarOuCriarAtivo($conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo);

        criarAporteService($conexao, $idWallet, $idAtivo, $typeContribution, $contributionQuantity, $contributionAmount, $contributionRecurrence, $contributionNotes, $contributionDate);

        $posicaoAtualizada = atualizarPosicao($conexao, $idWallet, $idAtivo, $typeContribution, $contributionQuantity, $contributionAmount);

        if (!$posicaoAtualizada) {
            throw new Exception("Quantidade Insuficiente para venda. A operação não pode ser concluída.");
        }
        $conexao->commit();

        echo json_encode([
            "sucesso" => true,
            "mensagem" => "Aporte cadastrado com sucesso"
        ]);
    } catch (Throwable $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }
}

function aporteRecorrente()
{
    global $conexao;

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
                    !carteiraPertenceAoUsuarioService(
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

                $id = criarAporteRecorrenteService(
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
                    !carteiraPertenceAoUsuarioService(
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

                $aportes = listarAporteRecorrenteService($conexao, $idCarteira);

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


                $aporte = getIdAporteRecorrenteService($conexao, $idAporteRecorrente);

                if (!$aporte) {

                    http_response_code(404);

                    echo json_encode([
                        "sucesso" => false,
                        "mensagem" => "Aporte recorrente não encontrado"
                    ]);

                    exit;
                }


                if (
                    !carteiraPertenceAoUsuarioService(
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


                $resultado = atualizarAporteRecorrenteService(
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
                    "mensagem" => "Ação inválida. Use criar, listar ou atualizar"
                ]);
        }
    } catch (Throwable $erro) {

        http_response_code(500);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro interno: " . $erro->getMessage()
        ]);
    }
}

function verifyAportesRecorrentes()
{
    global $conexao;

    try {

        $cronKey = $_SERVER["HTTP_X_CRON_KEY"] ?? "";

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode([
                "sucesso" => false,
                "mensagem" => "Método não permitido"
            ]);
            exit;
        }

        autenticarCron($cronKey);

        $resultado = execAportesRecorrentes($conexao);

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
}

function excluirAporte()
{

    global $conexao;

    try {

        $idUsuario = autenticar();

        $dados = json_decode(
            file_get_contents("php://input"),
            true
        );

        $idAporte = $dados["id_aporte"] ?? null;

        if (!$idAporte) {

            http_response_code(400);

            echo json_encode([
                "sucesso" => false,
                "mensagem" => "id_aporte é obrigatório"
            ]);

            return;
        }

        $excluido = excluirAporteService(
            $conexao,
            $idAporte,
            $idUsuario
        );

        if (!$excluido) {

            http_response_code(404);

            echo json_encode([
                "sucesso" => false,
                "mensagem" => "Aporte não encontrado"
            ]);

            return;
        }

        echo json_encode([
            "sucesso" => true,
            "mensagem" => "Aporte excluído com sucesso"
        ]);
    } catch (Throwable $erro) {

        http_response_code(500);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao excluir aporte: " . $erro->getMessage()
        ]);
    }
}
