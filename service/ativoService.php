<?php

//Busca o ativo pelo simbolo, se nao existir  cria e retorna o id do ativo
function buscarOuCriarAtivo(PDO $conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo)
{
    $busca = $conexao->prepare("SELECT id_ativo FROM ativo WHERE simbolo_ativo = ?");
    $busca->bindParam(1, $simboloAtivo);
    $busca->execute();
    $existente = $busca->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        return $existente["id_ativo"];
    }

    $sql = "INSERT INTO ativo (simbolo_ativo, nome_ativo, categoria_ativo) VALUES (?, ?, ?) RETURNING id_ativo";
    $insert = $conexao->prepare($sql);
    $insert->bindParam(1, $simboloAtivo);
    $insert->bindParam(2, $nomeAtivo);
    $insert->bindParam(3, $categoriaAtivo);
    $insert->execute();

    $novo = $insert->fetch(PDO::FETCH_ASSOC);

    return $novo["id_ativo"];
}