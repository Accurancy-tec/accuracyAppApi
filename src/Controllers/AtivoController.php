<?php
require_once __DIR__ . "/../config/cors.php";
header("Content-Type: application/json; charset=UTF-8");
 
require "../database/conexao.php";
require "../config/cors.php";
require_once "../accuracyApi/src/Middleware/Authentication.php";
require_once "../accuracyApi/src/Services/AtivoService.php";
require_once "../accuracyApi/src/Services/CotacaoService.php";
require_once "../accuracyApi/src/Services/PosicaoService.php";
require_once "../accuracyApi/src/Services/CarteiraService.php";
require_once "../accuracyApi/src/Services/AporteService.php";
 
autenticar();
 
$dados = json_decode(file_get_contents("php://input"), true);
$acao = $dados["acao"] ?? null;
 
try {
 
    if ($acao === "registrar_cotacao") {
 
        $simboloAtivo = $dados["simbolo_ativo"] ?? null;
        $nomeAtivo = $dados["nome_ativo"] ?? null;
        $categoriaAtivo = $dados["categoria_ativo"] ?? null;
        $precoAtivo = $dados["preco_ativo"] ?? null;
        $dataCotacao = $dados["data_cotacao"] ?? date("Y-m-d");
 
        if (!$simboloAtivo || !$nomeAtivo || !$categoriaAtivo || $precoAtivo === null) {
            http_response_code(400);
            echo json_encode([
                "sucesso" => false,
                "mensagem" => "simbolo_ativo, nome_ativo, categoria_ativo e preco_ativo são obrigatórios"
            ]);
            exit;
        }
 
        $idAtivo = buscarOuCriarAtivoService($conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo);
        salvarCotacaoService($conexao, $idAtivo, $dataCotacao, $precoAtivo);
 
        echo json_encode([
            "sucesso" => true,
            "mensagem" => "Ativo e cotação salvos com sucesso",
            "id_ativo" => $idAtivo
        ]);
        exit;
    }
 
    if ($acao === "listar") {
 
        $ativos = listarAtivoService($conexao);
 
        echo json_encode([
            "sucesso" => true,
            "ativos" => $ativos
        ]);
        exit;
    }
 
    http_response_code(400);
    echo json_encode([
        "sucesso" => false,
        "mensagem" => "acao inválida. Use 'registrar_cotacao' ou 'listar'"
    ]);
 
} catch (Throwable $erro) {
    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Erro: " . $erro->getMessage()
    ]);
}