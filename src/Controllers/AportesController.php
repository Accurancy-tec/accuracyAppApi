<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../../config/cors.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/PosicaoService.php";
require_once __DIR__ . "/../Services/AtivoService.php";
require_once __DIR__ . "/../Services/AporteService.php";
require_once __DIR__ . "/../Repositories/DetalheAporteRepository.php";
require_once __DIR__ . "/../Services/CotacaoService.php";
require_once __DIR__ . "/../Services/CarteiraService.php";
require_once __DIR__ . "/AutomacaoController.php";
require_once __DIR__ . "/../Repositories/AporteRepository.php";
require_once __DIR__ . "/../Services/PatrimonioService.php";
require_once __DIR__ . "/../Services/NotificacaoService.php";

function buscarAportesDoUsuario()
{
    global $conexao;

    try {
        $id_usuario = autenticar();
        $idCarteira = $_GET["id_carteira"] ?? null;

        if ($idCarteira === null) {
            echo json_encode([
                "sucesso" => false,
                "mensagem" => "ID da carteira não informado"
            ]);
            exit;
        }

        $idCarteira = (int) $idCarteira;

        $limite = isset($_GET["limite"]) ? (int) $_GET["limite"] : 200;
        $limite = max(1, min($limite, 200));

        $aportes = listarAportesUsuarioService($conexao, $id_usuario, $idCarteira, $limite);

        echo json_encode([
            "sucesso" => true,
            "quantidade" => count($aportes),
            "aportes" => $aportes
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao buscar aportes" . $e->getMessage()
        ]);
    }
}

const TIPOS_APORTE = ["Compra", "Venda", "Dividendo"];
const RECORRENCIAS_APORTE = ["Único", "Diário", "Semanal", "Mensal", "Anual"];
const CATEGORIAS_ATIVO = ["Ações", "Cripto", "Renda Fixa", "FIIs", "Internacional", "Futuros"];

// Diferença máxima aceita entre o preço da operação e a cotação atual (taxas e atraso da cotação).
const TOLERANCIA_PRECO_OPERACAO = 0.15;

function numeroPositivo($valor): ?float
{
    return is_numeric($valor) && is_finite((float) $valor) && (float) $valor > 0 ? (float) $valor : null;
}

/** Cotação atual usada para conferir o preço enviado pelo cliente (null quando não há cotação). */
function cotacaoDeReferencia(string $categoria, string $simbolo): ?float
{
    try {
        if (in_array($categoria, CATEGORIAS_COM_COTACAO_DE_MERCADO, true) && simboloNegociadoNaB3($simbolo)) {
            return cotacoesAtuaisB3([$simbolo])[$simbolo] ?? null;
        }

        if ($categoria === "Cripto") {
            return cotacoesAtuaisCripto([$simbolo])[$simbolo] ?? null;
        }
    } catch (Throwable $erro) {
        error_log("Cotação de referência indisponível para $simbolo: " . $erro->getMessage());
    }

    return null;
}

/** Valida uma compra, venda ou dividendo de renda variável. Lança AporteValidacaoException com a mensagem para o usuário. */
function validarOperacaoRendaVariavel(string $tipo, string $recorrencia, string $categoria, string $simbolo, ?float $quantidade, ?float $valor): void
{
    if (!in_array($tipo, TIPOS_APORTE, true)) {
        throw new AporteValidacaoException("Tipo de operação inválido.");
    }

    if (!in_array($recorrencia, RECORRENCIAS_APORTE, true)) {
        throw new AporteValidacaoException("Recorrência inválida.");
    }

    if ($recorrencia !== "Único" && $tipo !== "Compra") {
        throw new AporteValidacaoException("Só compras podem ser recorrentes.");
    }

    if (!in_array($categoria, CATEGORIAS_ATIVO, true) || $categoria === CATEGORIA_RENDA_FIXA) {
        throw new AporteValidacaoException("Categoria do ativo inválida.");
    }

    if ($simbolo === "" || !preg_match('/^[A-Z0-9.^_-]{1,15}$/', $simbolo)) {
        throw new AporteValidacaoException("Código do ativo inválido.");
    }

    if ($valor === null) {
        throw new AporteValidacaoException("Informe um valor maior que zero.");
    }

    if ($tipo !== "Dividendo" && $quantidade === null) {
        throw new AporteValidacaoException("Informe uma quantidade maior que zero.");
    }

    if ($tipo === "Dividendo" || $quantidade === null) {
        return;
    }

    $cotacao = cotacaoDeReferencia($categoria, $simbolo);
    $precoOperacao = $valor / $quantidade;

    if ($cotacao !== null && abs($precoOperacao - $cotacao) > $cotacao * TOLERANCIA_PRECO_OPERACAO) {
        throw new AporteValidacaoException(sprintf(
            "O preço desta operação está muito diferente da cotação atual de %s (R$ %s). Atualize a tela e tente de novo.",
            $simbolo,
            number_format($cotacao, 2, ",", ".")
        ));
    }
}

/** Quantidade que a carteira possui do ativo (0 se nunca teve). */
function quantidadeNaCarteira(PDO $conexao, int $idCarteira, string $simbolo): float
{
    $stmt = $conexao->prepare("
        SELECT ii.quantidade_item
        FROM item_investimento ii
        INNER JOIN ativo a ON a.id_ativo = ii.id_ativo
        WHERE ii.id_carteira = :carteira AND a.simbolo_ativo = :simbolo
    ");
    $stmt->execute([":carteira" => $idCarteira, ":simbolo" => $simbolo]);

    return (float) ($stmt->fetchColumn() ?: 0);
}

/** Saldo que a carteira pode usar agora: o da simulação ou o saldo geral (carteira real). */
function saldoDisponivelParaCarteira(PDO $conexao, int $idUsuario, int $idCarteira): float
{
    $carteira = buscarCarteiraDoUsuario($conexao, $idCarteira, $idUsuario);

    if ($carteira && $carteira["tipo_carteira"] === "Simulada") {
        return (float) $carteira["saldo_livre_carteira"];
    }

    return (float) (buscarSaldoRepository($conexao, $idUsuario) ?? 0);
}

// POST /aportes/registrar-aporte
function fazerAporte()
{
    global $conexao;

    $idUsuario = (int) autenticar();
    $dados = json_decode(file_get_contents("php://input"), true);
    $dados = is_array($dados) ? $dados : [];

    $idWallet = filter_var($dados["id_carteira"] ?? null, FILTER_VALIDATE_INT);
    $categoriaAtivo = (string) ($dados["categoria_ativo"] ?? "");
    $simboloAtivo = strtoupper(trim((string) ($dados["ativo_aporte"] ?? "")));
    $nomeAtivo = trim((string) ($dados["name_ativo"] ?? "")) ?: $simboloAtivo;
    $typeContribution = (string) ($dados["tipo_aporte"] ?? "");
    $contributionQuantity = numeroPositivo($dados["quantidade_aporte"] ?? null);
    $contributionAmount = numeroPositivo($dados["valor_aporte"] ?? null);
    $contributionRecurrence = (string) ($dados["recorrencia_aporte"] ?? "Único");
    $contributionNotes = isset($dados["observacao_aporte"]) ? mb_substr(trim((string) $dados["observacao_aporte"]), 0, 255) : null;
    $contributionDate = dataHojeCron();
    $unitPriceInformed = $dados["preco_unitario"] ?? null;
    $isFixedIncome = $categoriaAtivo === CATEGORIA_RENDA_FIXA || isset($dados["id_titulo"]);
    $fixedIncomeTitle = null;

    if (!$idWallet) {
        responderAporte(400, ["sucesso" => false, "mensagem" => "Informe a carteira do aporte."]);
        return;
    }

    $carteira = buscarCarteiraDoUsuario($conexao, $idWallet, $idUsuario);

    if (!$carteira) {
        responderAporte(403, ["sucesso" => false, "mensagem" => "Carteira inválida."]);
        return;
    }

    $simulada = $carteira["tipo_carteira"] === "Simulada";

    try {
        if ($isFixedIncome) {
            // Renda fixa: símbolo, nome, valor e condições vêm do cadastro do título (nunca do app).
            $fixedIncome = prepararAporteRendaFixa($conexao, $dados, $contributionDate);
            $simboloAtivo = $fixedIncome["simbolo"];
            $nomeAtivo = $fixedIncome["nome"];
            $categoriaAtivo = $fixedIncome["categoria"];
            $typeContribution = "Compra";
            $contributionRecurrence = "Único";
            $contributionQuantity = $fixedIncome["quantidade"];
            $contributionAmount = $fixedIncome["valor"];
            $fixedIncomeTitle = $fixedIncome["titulo"];
        } else {
            validarOperacaoRendaVariavel(
                $typeContribution,
                $contributionRecurrence,
                $categoriaAtivo,
                $simboloAtivo,
                $contributionQuantity,
                $contributionAmount
            );

            $possui = quantidadeNaCarteira($conexao, $idWallet, $simboloAtivo);

            if ($typeContribution === "Dividendo" && $possui <= 0) {
                throw new AporteValidacaoException("Dividendos só podem ser registrados para ativos que a carteira possui.");
            }

            if ($typeContribution === "Venda" && $contributionQuantity > $possui + 1e-8) {
                throw new AporteValidacaoException("Você não tem essa quantidade de $simboloAtivo nesta carteira.");
            }

            if ($typeContribution === "Dividendo") {
                $contributionQuantity = 0.0;
            }
        }

        $conexao->beginTransaction();

        $idAtivo = buscarOuCriarAtivo($conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo);

        $aporteCriado = criarAporteService($conexao, $idWallet, $idAtivo, $typeContribution, $contributionQuantity, $contributionAmount, $contributionRecurrence, $contributionNotes, $contributionDate);
        $idAporteCriado = (int) ($aporteCriado[0]["id_aporte"] ?? 0);

        if (!atualizarPosicao($conexao, $idWallet, $idAtivo, $typeContribution, $contributionQuantity, $contributionAmount)) {
            throw new AporteValidacaoException("Você não tem essa quantidade de $simboloAtivo nesta carteira.");
        }

        // Detalhes por tipo de investimento (fixed_income_/variable_income_contribution_details).
        if ($fixedIncomeTitle !== null) {
            if ($idAporteCriado <= 0) {
                throw new RuntimeException("Id do aporte não retornado; detalhe de renda fixa não gravado.");
            }
            registrarDetalheRendaFixa($conexao, $idAporteCriado, $fixedIncomeTitle);
        } elseif ($idAporteCriado > 0) {
            registrarDetalheRendaVariavel($conexao, $idAporteCriado, $typeContribution, $contributionQuantity, $contributionAmount, $unitPriceInformed);
        }

        if ($contributionRecurrence !== "Único" && $typeContribution === "Compra") {
            $existente = buscarRecorrenteAtivoIgual($conexao, $idWallet, $idAtivo, $contributionRecurrence);

            if ($existente) {
                $upd = $conexao->prepare("UPDATE aporte_recorrente SET valor_recorrente = ? WHERE id_aporte_recorrente = ?");
                $upd->execute([$contributionAmount, $existente["id_aporte_recorrente"]]);
            } else {
                $dia = (int) date("j", strtotime($contributionDate));
                $proxima = proximaDataRecorrenteCron($contributionDate, $contributionRecurrence, $dia);
                criarAporteRecorrenteService($conexao, $idWallet, $idAtivo, $contributionAmount, $contributionRecurrence, $dia, $proxima);
            }
        }

        $conexao->commit();
    } catch (AporteValidacaoException $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }

        // Regra de negócio: HTTP 200 + sucesso=false para o app e o site mostrarem a mensagem.
        responderAporte(200, ["sucesso" => false, "mensagem" => $erro->getMessage()]);
        return;
    } catch (Throwable $erro) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }

        error_log(sprintf(
            "AportesController::fazerAporte | classe=%s | codigo=%s | arquivo=%s:%d | mensagem=%s",
            get_class($erro),
            (string) $erro->getCode(),
            $erro->getFile(),
            $erro->getLine(),
            $erro->getMessage()
        ));

        // O bloqueio de saldo vem do trigger do banco; aqui só traduzimos a mensagem.
        if (stripos($erro->getMessage(), "saldo insuficiente") !== false) {
            responderAporte(200, [
                "sucesso" => false,
                "mensagem" => $simulada ? "Saldo da simulação insuficiente" : "Saldo insuficiente"
            ]);
            return;
        }

        responderAporte(200, ["sucesso" => false, "mensagem" => "Não foi possível concluir a operação. Tente novamente."]);
        return;
    }

    notificarOperacao(
        $conexao,
        $idUsuario,
        $typeContribution,
        ["simbolo" => $simboloAtivo, "nome" => $nomeAtivo, "categoria" => $categoriaAtivo],
        (float) $contributionQuantity,
        (float) $contributionAmount,
        $carteira["nome_carteira"]
    );

    try {
        $patrimonio = montarPatrimonioUsuario($conexao, $idUsuario, ["id_carteira" => $idWallet]);
        verificarMetasAlcancadas($conexao, $idUsuario, $patrimonio["carteiras"]);
    } catch (Throwable $erro) {
        error_log("Meta não verificada após o aporte: " . $erro->getMessage());
    }

    responderAporte(200, [
        "sucesso" => true,
        "mensagem" => "Aporte cadastrado com sucesso",
        "id_aporte" => $idAporteCriado,
        "saldo_disponivel" => round(saldoDisponivelParaCarteira($conexao, $idUsuario, $idWallet), 2)
    ]);
}

function responderAporte(int $status, array $corpo): void
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

// POST /aportes/excluir-aporte   corpo: { "id_aporte": 10 }
function excluirAporte()
{
    global $conexao;

    $idUsuario = (int) autenticar();
    $dados = json_decode(file_get_contents("php://input"), true);
    $idAporte = filter_var($dados["id_aporte"] ?? null, FILTER_VALIDATE_INT);

    if (!$idAporte) {
        responderAporte(400, ["sucesso" => false, "mensagem" => "id_aporte é obrigatório"]);
        return;
    }

    try {
        if (!excluirAporteService($conexao, $idAporte, $idUsuario)) {
            responderAporte(404, ["sucesso" => false, "mensagem" => "Aporte não encontrado"]);
            return;
        }

        responderAporte(200, ["sucesso" => true, "mensagem" => "Aporte excluído e saldo ajustado"]);
    } catch (AporteValidacaoException $erro) {
        responderAporte(409, ["sucesso" => false, "mensagem" => $erro->getMessage()]);
    } catch (Throwable $erro) {
        error_log("Erro ao excluir aporte: " . $erro->getMessage());
        responderAporte(500, ["sucesso" => false, "mensagem" => "Não foi possível excluir o aporte agora."]);
    }
}

function listarAportes()
{
    global $conexao;

    try {
        $idUsuario = autenticar();

        $dados = json_decode(file_get_contents("php://input"), true);
        $Choise = $_GET["Choise"] ?? $dados["Choise"] ?? null;

        $aportes = listarAportesService($conexao, $idUsuario, $Choise);

        echo json_encode([
            "sucesso" => true,
            "aportes" => $aportes
        ]);
    } catch (Throwable $erro) {
        http_response_code(500);
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao listar aportes: " . $erro->getMessage()
        ]);
    }
}

function listarTitulosRF(){
    global $conexao;

    try {
        $titulos = listarTitulosRendaFixaService($conexao);

        http_response_code(200);

        echo json_encode([
            "success" => true,
            "titulos" => $titulos
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    } catch (Throwable $error) {
        error_log("Erro ao listar títulos de renda fixa: " . $error->getMessage());

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Erro interno ao buscar os títulos de renda fixa."
        ], JSON_UNESCAPED_UNICODE);
    }
}
