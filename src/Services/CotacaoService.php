<?php
 
  //Salva o preço de fechamento de um ativo em uma data.
  //Se já existir cotação desse ativo nessa data, atualiza o preço, se nao insere uma nova cotacao
  
    function salvarCotacaoService(PDO $conexao, $idAtivo, $dataCotacao, $precoAtivo)
    {
        return salvarCotacao($conexao, $idAtivo, $dataCotacao, $precoAtivo);
    }
 

 
    function buscarUltimaCotacaoService(PDO $conexao, $idAtivo)
    {
        return buscarUltimaCotacao($conexao, $idAtivo);
    }

?>
 