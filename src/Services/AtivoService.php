<?php
require_once __DIR__ . "/../Repositories/AtivoRepository.php";

//Busca o ativo pelo simbolo, se nao existir  cria e retorna o id do ativo
function buscarOuCriarAtivoService(PDO $conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo)
{
    return buscarOuCriarAtivo($conexao, $simboloAtivo, $nomeAtivo, $categoriaAtivo);
}

function listarAtivoService(PDO $conexao){
    return listarAtivos($conexao);
}
