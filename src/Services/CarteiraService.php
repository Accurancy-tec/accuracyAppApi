<?php

require_once __DIR__ . "/../Repositories/CarteiraRepository.php";
// cria a carteira do usuario

// e retorna o id dela
function criarCarteiraService(PDO $conexao, $idUsuario, $nomeCarteira, $tipoCarteira, $saldoLivre)
{
    return criarCarteira($conexao, $idUsuario, $nomeCarteira, $tipoCarteira, $saldoLivre); 
}
