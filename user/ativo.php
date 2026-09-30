<?php
require "../config/cors.php";
header("Content-Type: application/json; charset=UTF-8");
 
require "../database/conexao.php";
require_once "../Authentication/Authentication.php";
require_once "../service/ativoService.php";
require_once "../service/cotacaoService.php";
 
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
 
        $idAtivo = buscarOuCriarAtivo($conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo);
        salvarCotacao($conexao, $idAtivo, $dataCotacao, $precoAtivo);
 
        echo json_encode([
            "sucesso" => true,
            "mensagem" => "Ativo e cotação salvos com sucesso",
            "id_ativo" => $idAtivo
        ]);
        exit;
    }
 
    if ($acao === "listar") {
 
        $sql = $conexao->prepare(
            "SELECT id_ativo, simbolo_ativo, nome_ativo, categoria_ativo, cotacao_automatica_ativo
             FROM ativo
             ORDER BY nome_ativo ASC"
        );
        $sql->execute();
 
        $ativos = $sql->fetchAll(PDO::FETCH_ASSOC);
 
        foreach ($ativos as &$ativo) {
            $ativo["ultima_cotacao"] = buscarUltimaCotacao($conexao, $ativo["id_ativo"]);
        }
 
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