<?php
    require_once __DIR__ . "/../Repositories/CotacaoRepository.php";
    
  //Salva o preço de fechamento de um ativo em uma data.
  //Se já existir cotação desse ativo nessa data, atualiza o preço, se nao insere uma nova cotacao
  
    function salvarCotacaoService(PDO $conexao, $idAtivo, $dataCotacao, $precoAtivo)
    {
        return salvarCotacao($conexao, $idAtivo, $dataCotacao, $precoAtivo);
    }
 

 
    function getUltimaCotacaoService(PDO $conexao, $idAtivo)
    {
        return getUltimaCotacao($conexao, $idAtivo);
    }