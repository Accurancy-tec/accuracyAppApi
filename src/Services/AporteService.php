<?php
require_once __DIR__ . "/../Repositories/AporteRepository.php";

function criarAporteService(PDO $conexao, $id_carteira, $id_ativo, $tipo_aporte, $quantidade_aporte, $valor_aporte, $recorrencia_aporte, $obs_aporte, $data_aporte)
{

    return criarAporte($conexao, $id_carteira, $id_ativo, $tipo_aporte, $quantidade_aporte, $valor_aporte, $recorrencia_aporte, $obs_aporte, $data_aporte);
}

function listarAportesUsuarioService(PDO $conexao, $id_usuario)
{
    return listarAportesUsuario($conexao, $id_usuario);
}

function criarAporteRecorrenteService(PDO $conexao, $idCarteira, $idAtivo, $valorRecorrente, $frequenciaRecorrente, $diaReferenciaRecorrente, $proximaExecucaoRecorrente)
{
    return criarAporteRecorrente($conexao, $idCarteira, $idAtivo, $valorRecorrente, $frequenciaRecorrente, $diaReferenciaRecorrente, $proximaExecucaoRecorrente);
}

function listarAporteRecorrenteService(PDO $conexao, $id_carteira)
{
    return listarAporteRecorrente($conexao, $id_carteira);
}

function getAportesRecorrentesService(PDO $conexao)
{
    return getAportesRecorrentes($conexao);
}

function updateAporteRecorrenteService(PDO $conexao, $proximaExecucao, $idAporteRecorrente)
{

    return updateAporteRecorrente($conexao, $proximaExecucao, $idAporteRecorrente);
}

function getIdAporteRecorrenteService(PDO $conexao, $idAporteRecorrente){
    return getIdAporteRecorrente($conexao, $idAporteRecorrente);
}

function atualizarAporteRecorrenteService(PDO $conexao, $idAporteRecorrente, $valorRecorrente, $frequenciaRecorrente, $diaReferenciaRecorrente, $ativoFlagRecorrente){
    return atualizarAporteRecorrente($conexao, $idAporteRecorrente, $valorRecorrente, $frequenciaRecorrente, $diaReferenciaRecorrente, $ativoFlagRecorrente);
}
