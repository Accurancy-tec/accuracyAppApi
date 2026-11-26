<?php

// Avisos para o usuário sobre o que aconteceu na conta dele: operações, depósitos,
// metas alcançadas e respostas do suporte. Nunca interrompem a operação principal.

require_once __DIR__ . "/../Repositories/NotificacaoRepository.php";

const TIPOS_NOTIFICACAO = ["Aporte", "Preço", "Notícia", "Sistema", "Aprendizado", "Meta"];

function formatarReais(float $valor): string
{
    return "R$ " . number_format($valor, 2, ",", ".");
}

/** Grava a notificação; uma falha aqui só fica no log (dentro de transação usa SAVEPOINT). */
function notificarUsuario(PDO $conexao, int $idUsuario, string $titulo, string $mensagem, string $tipo = "Sistema"): void
{
    if (!in_array($tipo, TIPOS_NOTIFICACAO, true)) {
        $tipo = "Sistema";
    }

    $emTransacao = $conexao->inTransaction();

    try {
        if ($emTransacao) {
            $conexao->exec("SAVEPOINT notificacao");
        }

        inserirNotificacao($conexao, $idUsuario, $titulo, $mensagem, $tipo);

        if ($emTransacao) {
            $conexao->exec("RELEASE SAVEPOINT notificacao");
        }
    } catch (Throwable $erro) {
        if ($emTransacao) {
            $conexao->exec("ROLLBACK TO SAVEPOINT notificacao");
        }
        error_log("Notificação não registrada (usuário $idUsuario): " . $erro->getMessage());
    }
}

/** Texto do aviso de uma operação registrada (compra, venda, dividendo ou aplicação em renda fixa). */
function notificarOperacao(PDO $conexao, int $idUsuario, string $tipoAporte, array $ativo, float $quantidade, float $valor, string $nomeCarteira): void
{
    $simbolo = $ativo["simbolo"];
    $quantidadeTexto = rtrim(rtrim(number_format($quantidade, 8, ",", "."), "0"), ",");

    if ($tipoAporte === "Compra" && ($ativo["categoria"] ?? "") === "Renda Fixa") {
        $titulo = "Aplicação registrada";
        $mensagem = formatarReais($valor) . " aplicados em {$ativo["nome"]} na carteira $nomeCarteira.";
    } elseif ($tipoAporte === "Compra") {
        $titulo = "Compra registrada";
        $mensagem = "$quantidadeTexto × $simbolo por " . formatarReais($valor) . " na carteira $nomeCarteira.";
    } elseif ($tipoAporte === "Venda") {
        $titulo = "Venda registrada";
        $mensagem = "$quantidadeTexto × $simbolo vendidos por " . formatarReais($valor) . " na carteira $nomeCarteira.";
    } else {
        $titulo = "Dividendo recebido";
        $mensagem = formatarReais($valor) . " de $simbolo creditados na carteira $nomeCarteira.";
    }

    notificarUsuario($conexao, $idUsuario, $titulo, $mensagem, "Aporte");
}

/**
 * Avisa uma única vez quando o patrimônio de uma carteira alcança a meta definida.
 * Recebe as carteiras já consolidadas pelo PatrimonioService.
 */
function verificarMetasAlcancadas(PDO $conexao, int $idUsuario, array $carteiras): void
{
    foreach ($carteiras as $carteira) {
        if (empty($carteira["meta_atingida"])) {
            continue;
        }

        try {
            if (!registrarMetaAtingida($conexao, (int) $carteira["id_carteira"])) {
                continue;
            }
        } catch (Throwable $erro) {
            error_log("Falha ao registrar meta atingida: " . $erro->getMessage());
            continue;
        }

        notificarUsuario(
            $conexao,
            $idUsuario,
            "Meta alcançada!",
            "A carteira {$carteira["nome_carteira"]} chegou a " . formatarReais($carteira["patrimonio_atual"])
                . " e bateu a meta de " . formatarReais($carteira["meta_carteira"]) . ".",
            "Meta"
        );
    }
}
