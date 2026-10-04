<?php

const CATEGORIAS_COTACAO_AUTOMATICA = ["Ações", "FIIs"];

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

    // texto "1"/"0": o Postgres converte sozinho, funciona se a coluna for smallint ou boolean
    $cotacaoAutomatica = in_array($categoriaAtivo, CATEGORIAS_COTACAO_AUTOMATICA, true) ? "1" : "0";

    $sql = "INSERT INTO ativo (simbolo_ativo, nome_ativo, categoria_ativo, cotacao_automatica_ativo) VALUES (?, ?, ?, ?) RETURNING id_ativo";
    $insert = $conexao->prepare($sql);
    $insert->bindParam(1, $simboloAtivo);
    $insert->bindParam(2, $nomeAtivo);
    $insert->bindParam(3, $categoriaAtivo);
    $insert->bindParam(4, $cotacaoAutomatica);
    $insert->execute();

    $novo = $insert->fetch(PDO::FETCH_ASSOC);

    return $novo["id_ativo"];
}
?>