<?php
require_once __DIR__ . "/../config/cors.php";
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

        // Catálogo de ativos é COMPARTILHADO. Ativo com cotação automática é preço do cron (Brapi);
        // senão qualquer usuário logado mudava o preço do PETR4 para todo mundo.
        $stmtAuto = $conexao->prepare("SELECT cotacao_automatica_ativo::int FROM ativo WHERE id_ativo = ?");
        $stmtAuto->execute([$idAtivo]);

        if ((int) $stmtAuto->fetchColumn() === 1) {
            http_response_code(403);
            echo json_encode([
                "sucesso" => false,
                "mensagem" => "Este ativo tem cotação automática; o preço é atualizado pelo servidor."
            ]);
            exit;
        }

        if (!is_numeric($precoAtivo) || (float) $precoAtivo <= 0) {
            http_response_code(400);
            echo json_encode(["sucesso" => false, "mensagem" => "preco_ativo deve ser um número maior que zero."]);
            exit;
        }

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
?>