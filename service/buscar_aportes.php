<?php 
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . "/../database/conexao.php";
require_once __DIR__ . "/../src/Middleware/Authentication.php";

// Esse arquivo faz a mesma requisição que o dashboard.php, apenas separei as reponsabilidades e também estava usando pra testar a carteira
try {

    $id_usuario = autenticar();
    $sql = $conexao->prepare("
        SELECT
            a.id_aporte,
            at.simbolo_ativo AS ativo_aporte,
            at.nome_ativo AS name_ativo,
            at.categoria_ativo AS categoria_ativo,
            a.quantidade_aporte,
            a.valor_aporte,
            a.tipo_aporte,
            a.recorrencia_aporte
        FROM Aporte a
        INNER JOIN Carteira c
            ON c.id_carteira = a.id_carteira
        INNER JOIN Ativo at
            ON at.id_ativo = a.id_ativo
        WHERE c.id_usuario = ?
        ORDER BY a.id_aporte DESC
        LIMIT 4
    ");

    $sql->execute([$id_usuario]);

    $resultado = $sql->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "sucesso" => true,
        "quantidade" => count($resultado),
        "ativos" => $resultado
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "mensagem" => $e->getMessage()
    ]);
}
?>