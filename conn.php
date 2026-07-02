<?php
//Conexao com o banco

$host = "localhost";
$database = "accurancy_db";
$user = "root";
$password = "";

try
{
    $pdo = new PDO("mysql:host=$host;dbname=$database" . $user, $password);
}
catch(PDOException $e){
    die($e->getMessage());
}
?>