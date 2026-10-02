<?php

require_once __DIR__ . "/vendor/autoload.php";

use Dotenv\Dotenv;

echo "DIR: " . __DIR__ . "<br>";

var_dump(file_exists(__DIR__ . "/.env"));
var_dump(is_readable(__DIR__ . "/.env"));

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "<br>ENV CARREGADO<br>";

echo "DB_HOST existe: ";
var_dump(isset($_ENV["DB_HOST"]));