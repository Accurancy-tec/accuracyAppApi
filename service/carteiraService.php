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