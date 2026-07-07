<?php 

require_once 'service/BrapiService.php';

$ticker = $_GET['ticker'];

$reponse = buscarAcao($ticker);

echo $reponse;
?>