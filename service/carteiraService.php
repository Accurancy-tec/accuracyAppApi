<?php

// cria a carteira do usuario

// e retorna o id dela
function criarCarteira(PDO $conexao, $idUsuario, $nomeCarteira, $tipoCarteira, $saldoLivre)
{
    $sql = "INSERT INTO carteira (id_usuario, nome_carteira, tipo_carteira, saldo_livre_carteira) VALUES (?, ?, ?, ?) RETURNING id_carteira";
    $insert = $conexao->prepare($sql);
    $insert->bindParam(1, $idUsuario);
    $insert->bindParam(2, $nomeCarteira);
    $insert->bindParam(3, $tipoCarteira);
    $insert->bindParam(4, $saldoLivre);
    $insert->execute();

    $nova = $insert->fetch(PDO::FETCH_ASSOC);

    return $nova["id_carteira"];
}
function listarCarteiras(PDO $conexao, $idUsuario)
{
    $sql = "SELECT id_carteira, nome_carteira, tipo_carteira, saldo_livre_carteira
            FROM carteira
            WHERE id_usuario = ?
            ORDER BY id_carteira";

    $consulta = $conexao->prepare($sql);
    $consulta->bindParam(1, $idUsuario);
    $consulta->execute();

    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}