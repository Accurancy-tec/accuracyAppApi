<?php

require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/NotificacaoService.php";

const NOTIFICACOES_LIMITE_PADRAO = 30;
const NOTIFICACOES_LIMITE_MAXIMO = 100;

function responderNotificacoes(int $status, array $corpo): void
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// GET /notificacoes/listar?limite=30
function listarNotificacoes(): void
{
    global $conexao;
    $idUsuario = (int) autenticar();

    $limite = filter_var($_GET["limite"] ?? NOTIFICACOES_LIMITE_PADRAO, FILTER_VALIDATE_INT);
    $limite = $limite === false ? NOTIFICACOES_LIMITE_PADRAO : max(1, min($limite, NOTIFICACOES_LIMITE_MAXIMO));

    try {
        responderNotificacoes(200, [
            "success" => true,
            "nao_lidas" => contarNotificacoesNaoLidas($conexao, $idUsuario),
            "notificacoes" => listarNotificacoesUsuario($conexao, $idUsuario, $limite),
        ]);
    } catch (Throwable $erro) {
        error_log("Erro ao listar notificações: " . $erro->getMessage());
        responderNotificacoes(500, ["success" => false, "message" => "Não foi possível carregar as notificações."]);
    }
}

// GET /notificacoes/contador  (leve, usado para o número no sino)
function contarNotificacoes(): void
{
    global $conexao;
    $idUsuario = (int) autenticar();

    try {
        responderNotificacoes(200, ["success" => true, "nao_lidas" => contarNotificacoesNaoLidas($conexao, $idUsuario)]);
    } catch (Throwable $erro) {
        error_log("Erro ao contar notificações: " . $erro->getMessage());
        responderNotificacoes(500, ["success" => false, "message" => "Não foi possível consultar as notificações."]);
    }
}

// POST /notificacoes/marcar-lidas   corpo: { "id_notificacao": 12 }  (sem id = todas)
function marcarNotificacoes(): void
{
    global $conexao;
    $idUsuario = (int) autenticar();

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        responderNotificacoes(405, ["success" => false, "message" => "Use POST."]);
        return;
    }

    $dados = json_decode(file_get_contents("php://input"), true) ?: [];
    $id = isset($dados["id_notificacao"]) ? filter_var($dados["id_notificacao"], FILTER_VALIDATE_INT) : null;

    if ($id === false || ($id !== null && $id <= 0)) {
        responderNotificacoes(400, ["success" => false, "message" => "Notificação inválida."]);
        return;
    }

    try {
        $alteradas = marcarNotificacoesLidas($conexao, $idUsuario, $id);

        responderNotificacoes(200, [
            "success" => true,
            "marcadas" => $alteradas,
            "nao_lidas" => contarNotificacoesNaoLidas($conexao, $idUsuario),
        ]);
    } catch (Throwable $erro) {
        error_log("Erro ao marcar notificações: " . $erro->getMessage());
        responderNotificacoes(500, ["success" => false, "message" => "Não foi possível atualizar as notificações."]);
    }
}
