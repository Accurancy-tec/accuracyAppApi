<?php
// SERVICE = regras do sistema. Chama o repository.

require_once __DIR__ . "/../Repositories/CursoRepository.php";

function listarTrilhasService(PDO $conexao)
{
    return buscarTrilhas($conexao);
}

function buscarTrilhaService(PDO $conexao, $idTrilha)
{
    return buscarTrilhaPorId($conexao, $idTrilha);
}
