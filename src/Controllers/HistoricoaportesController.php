<?php

require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Repositories/HistoricoaportesRepository.php";

const HISTORICO_LIMITE_MAXIMO = 200;

/** Lê e valida os filtros do histórico (filtro inválido é ignorado, nunca vira SQL). */
function filtrosHistoricoDaRequisicao(): array
{
    $filtros = [];

    $idCarteira = filter_var($_GET["id_carteira"] ?? null, FILTER_VALIDATE_INT);
    if ($idCarteira) {
        $filtros["id_carteira"] = $idCarteira;
    }

    $tipo = (string) ($_GET["tipo"] ?? "");
    if (in_array($tipo, ["Compra", "Venda", "Dividendo"], true)) {
        $filtros["tipo"] = $tipo;
    }

    foreach (["inicio", "fim"] as $campo) {
        $data = (string) ($_GET[$campo] ?? "");
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) && strtotime($data) !== false) {
            $filtros[$campo] = $data;
        }
    }

    $busca = trim((string) ($_GET["busca"] ?? ""));
    if ($busca !== "" && mb_strlen($busca, "UTF-8") <= 60) {
        $filtros["busca"] = $busca;
    }

    return $filtros;
}

// GET /aportes/historico-aportes
// Sem parâmetros devolve todos os aportes do usuário (formato usado pelo site e pelo app).
// Opcionais: id_carteira, tipo, inicio, fim, busca, pagina + limite e incluir_depositos=1.
function buscarHistoricoAportes()
{
    global $conexao;

    try {
        $idUsuario = (int) autenticar();
        $filtros = filtrosHistoricoDaRequisicao();

        $limite = isset($_GET["limite"]) ? filter_var($_GET["limite"], FILTER_VALIDATE_INT) : null;
        $limite = $limite ? max(1, min($limite, HISTORICO_LIMITE_MAXIMO)) : null;
        $pagina = max(1, (int) ($_GET["pagina"] ?? 1));

        $resumo = resumirHistoricoAportes($conexao, $idUsuario, $filtros);
        $aportes = listarHistoricoAportesUsuario(
            $conexao,
            $idUsuario,
            $filtros,
            $limite,
            $limite ? ($pagina - 1) * $limite : 0
        );

        $resposta = [
            "sucesso" => true,
            "aportes" => $aportes,
            "resumo" => $resumo,
            "pagina" => $limite ? $pagina : 1,
            "total_paginas" => $limite ? max(1, (int) ceil($resumo["quantidade"] / $limite)) : 1,
        ];

        if (($_GET["incluir_depositos"] ?? "") === "1") {
            $depositos = listarDepositosUsuario($conexao, $idUsuario, $filtros);
            $resposta["depositos"] = $depositos;
            $resposta["resumo"]["total_depositos"] = round(array_sum(array_map("floatval", array_column($depositos, "valor"))), 2);
        }

        echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $erro) {
        error_log("Erro ao buscar histórico de aportes: " . $erro->getMessage());
        http_response_code(500);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Não foi possível carregar o histórico agora."
        ], JSON_UNESCAPED_UNICODE);
    }
}
