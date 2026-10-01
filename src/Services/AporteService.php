<?php

    require_once __DIR__ . "/../Repositories/AporteRepository.php";

    function cadastrarAporteService(PDO $conexao, $id_carteira, $id_ativo, $tipo_aporte, $quantidade_aporte, $valor_aporte, $recorrencia_aporte, $obs_aporte, $data_aporte){

        return cadastrarAporte($conexao, $id_carteira, $id_ativo, $tipo_aporte, $quantidade_aporte, $valor_aporte, $recorrencia_aporte, $obs_aporte, $data_aporte);
    }

    function listarAportesUsuarioService(PDO $conexao, $id_usuario){
        return listarAportesUsuario($conexao, $id_usuario); 
    }
?>