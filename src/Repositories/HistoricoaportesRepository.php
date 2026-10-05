<?php
// Lista TODOS os aportes do usuário (sem LIMIT), já com data, carteira e observação.

function listarHistoricoAportesUsuario(PDO $conexao, $idUsuario)
{
    $sql = $conexao->prepare("
        SELECT
            a.id_aporte,
            a.id_carteira,
            ati.simbolo_ativo AS ativo_aporte,
            ati.nome_ativo AS name_ativo,
            ati.categoria_ativo,
            a.quantidade_aporte,
            a.valor_aporte,
            a.tipo_aporte,
            a.recorrencia_aporte,
            a.observacao_aporte,
            to_char(a.data_aporte, 'YYYY-MM-DD') AS data_aporte
        FROM aporte a
        INNER JOIN carteira c ON c.id_carteira = a.id_carteira
        INNER JOIN ativo ati ON ati.id_ativo = a.id_ativo
        WHERE c.id_usuario = ?
        ORDER BY a.data_aporte DESC, a.id_aporte DESC
    ");
 
    $sql->execute([$idUsuario]);
 
    return $sql->fetchAll(PDO::FETCH_ASSOC);
}