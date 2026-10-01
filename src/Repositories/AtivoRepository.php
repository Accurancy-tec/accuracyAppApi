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

function listarAtivos(PDO $conexao){
    $sql = $conexao->prepare(
        "SELECT id_ativo, simbolo_ativo, nome_ativo, categoria_ativo, cotacao_automatica_ativo FROM ativo ORDER BY nome_ativo ASC"
    );

    $sql->execute();

    $ativos = $sql->fetchAll(PDO::FETCH_ASSOC);
 
    foreach ($ativos as &$ativo) {
        $ativo["ultima_cotacao"] = buscarUltimaCotacaoService($conexao, $ativo["id_ativo"]);
    }

    return $ativos;
}