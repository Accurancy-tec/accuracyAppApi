<?php
// Confere se a carteira pertence mesmo ao usuário autenticado,
// pra ninguém criar aporte recorrente em carteira de outra pessoa
function criarAporte(PDO $conexao, $id_carteira, $id_ativo, $tipo_aporte, $quantidade_aporte, $valor_aporte, $recorrencia_aporte, $obs_aporte, $data_aporte){

    $sql = $conexao->prepare("INSERT INTO aporte (
    id_carteira, 
    id_ativo, 
    tipo_aporte,
    quantidade_aporte,
    valor_aporte,
    recorrencia_aporte,
    observacao_aporte,
    data_aporte) 
    VALUES (:id_carteira, :id_ativo, :tipo_aporte, :quantidade_aporte, :valor_aporte, :recorrencia_aporte, :obs_aporte, :data_aporte)"
    ); 

    $sql->execute([
        ':id_carteira' => $id_carteira,
        ':id_ativo' => $id_ativo,
        ':tipo_aporte' => $tipo_aporte,
        ':quantidade_aporte' => $quantidade_aporte,
        ':valor_aporte' => $valor_aporte,
        ':recorrencia_aporte' => $recorrencia_aporte,
        ':obs_aporte' => $obs_aporte,
        ':data_aporte' => $data_aporte
    ]);

    return $sql->fetchAll(PDO::FETCH_ASSOC);
   
}
// Cria um novo aporte recorrente pra uma carteira/ativo
function criarAporteRecorrente(PDO $conexao, $idCarteira, $idAtivo, $valorRecorrente, $frequenciaRecorrente, $diaReferenciaRecorrente, $proximaExecucaoRecorrente)
{
    $sql = "INSERT INTO aporte_recorrente
                (id_carteira, id_ativo, valor_recorrente, frequencia_recorrente, dia_referencia_recorrente, ativo_flag_recorrente, proxima_execucao_recorrente)
            VALUES
                (:carteira, :ativo, :valor, :frequencia, :dia, 1, :proxima)
            RETURNING id_aporte_recorrente";
 
    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':carteira', $idCarteira);
    $stmt->bindParam(':ativo', $idAtivo);
    $stmt->bindParam(':valor', $valorRecorrente);
    $stmt->bindParam(':frequencia', $frequenciaRecorrente);
    $stmt->bindParam(':dia', $diaReferenciaRecorrente);
    $stmt->bindParam(':proxima', $proximaExecucaoRecorrente);
    $stmt->execute();
 
    $novo = $stmt->fetch(PDO::FETCH_ASSOC);
 
    return $novo["id_aporte_recorrente"];
}
 
// Lista os aportes recorrentes de uma carteira
function listarAporteRecorrente(PDO $conexao, $idCarteira)
{
    $sql = "SELECT r.id_aporte_recorrente, r.id_carteira, r.id_ativo, a.simbolo_ativo, a.nome_ativo,
                   r.valor_recorrente, r.frequencia_recorrente, r.dia_referencia_recorrente,
                   r.ativo_flag_recorrente, r.proxima_execucao_recorrente, r.criado_em
            FROM aporte_recorrente r
            JOIN ativo a ON a.id_ativo = r.id_ativo
            WHERE r.id_carteira = :carteira
            ORDER BY r.criado_em DESC";
 
    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':carteira', $idCarteira);
    $stmt->execute();
 
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
 
// Atualiza valor, frequência, dia de referência e status (ativo/inativo) de um aporte recorrente
function atualizarAporteRecorrente(PDO $conexao, $idAporteRecorrente, $valorRecorrente, $frequenciaRecorrente, $diaReferenciaRecorrente, $ativoFlagRecorrente)
{
    $sql = "UPDATE aporte_recorrente
            SET valor_recorrente = :valor,
                frequencia_recorrente = :frequencia,
                dia_referencia_recorrente = :dia,
                ativo_flag_recorrente = :ativoFlag
            WHERE id_aporte_recorrente = :id";
 
    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':valor', $valorRecorrente);
    $stmt->bindParam(':frequencia', $frequenciaRecorrente);
    $stmt->bindParam(':dia', $diaReferenciaRecorrente);
    $stmt->bindParam(':ativoFlag', $ativoFlagRecorrente, PDO::PARAM_INT);
    $stmt->bindParam(':id', $idAporteRecorrente);
    $stmt->execute();
 
    return $stmt->rowCount() > 0;
}

function listarAportesUsuario(PDO $conexao, $id_usuario, $limite = 200){
    $limite = max(1, (int) $limite);

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
        INNER JOIN Carteira c ON c.id_carteira = a.id_carteira
        INNER JOIN Ativo at ON at.id_ativo = a.id_ativo
        WHERE c.id_usuario = ?
        ORDER BY a.id_aporte DESC
        LIMIT $limite
    ");

    $sql->execute([$id_usuario]);

    return $sql->fetchAll(PDO::FETCH_ASSOC);

    
}

function getIdAporteRecorrente(PDO $conexao, $idAporteRecorrente){
    $sql = "SELECT id_carteira FROM aporte_recorrente WHERE id_aporte_recorrente = ? ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idAporteRecorrente]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAportesRecorrentes(PDO $conexao){
    $sql = "
        SELECT
            id_aporte_recorrente,
            id_carteira,
            id_ativo,
            valor_recorrente,
            frequencia_recorrente,
            proxima_execucao_recorrente
        FROM aporte_recorrente
        WHERE ativo_flag_recorrente = TRUE
         AND proxima_execucao_recorrente <= CURRENT_DATE
        ORDER BY proxima_execucao_recorrente
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateAporteRecorrente(PDO $conexao, $proximaExecucao, $idAporteRecorrente){
    
    // Atualiza o aporte recorrente
    $sqlUpdate = "UPDATE aporte_recorrente SET proxima_execucao_recorrente = :proxima WHERE id_aporte_recorrente = :id";

    $update = $conexao->prepare($sqlUpdate);

    $update->execute([
        ":proxima" => $proximaExecucao,
        ":id" => $idAporteRecorrente
    ]);

    return $update;

}
function excluirAporteRepository(PDO $conexao, $idAporte, $idUsuario)
{
    $sql = "
        DELETE FROM aporte
        WHERE id_aporte = ?
        AND id_carteira IN (
            SELECT id_carteira
            FROM carteira
            WHERE id_usuario = ?
        )
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idAporte, $idUsuario]);

    return $stmt->rowCount() > 0;
}