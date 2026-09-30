<?php
 
// Confere se a carteira pertence mesmo ao usuário autenticado,
// pra ninguém criar aporte recorrente em carteira de outra pessoa
function carteiraPertenceAoUsuario(PDO $conexao, $idCarteira, $idUsuario)
{
    $sql = "SELECT id_carteira FROM carteira WHERE id_carteira = ? AND id_usuario = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idCarteira, $idUsuario]);
 
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
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
 