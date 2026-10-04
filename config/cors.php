<?php
// Sites autorizados a usar a API
$origensPermitidas = [
    "https://accuracy.site.je",
    "http://accuracy.site.je",
    "https://www.accuracy.site.je",
];

$origem = $_SERVER["HTTP_ORIGIN"] ?? "";

// Aceita localhost e 127.0.0.1, com ou sem porta (5500, 3000, 8080...)
$origemLocal = (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origem);

if ($origem !== "" && ($origemLocal || in_array($origem, $origensPermitidas, true))) {
    header("Access-Control-Allow-Origin: " . $origem);
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Max-Age: 86400");
    header("Vary: Origin");
}

// Resposta à "pergunta prévia" do navegador (preflight)
if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
    http_response_code(204);
    exit;
}

// Recupera o header Authorization, que algumas hospedagens descartam
if (empty($_SERVER["HTTP_AUTHORIZATION"])) {
    if (!empty($_SERVER["REDIRECT_HTTP_AUTHORIZATION"])) {
        $_SERVER["HTTP_AUTHORIZATION"] = $_SERVER["REDIRECT_HTTP_AUTHORIZATION"];
    } elseif (function_exists("getallheaders")) {
        foreach (getallheaders() as $nome => $valor) {
            if (strtolower($nome) === "authorization") {
                $_SERVER["HTTP_AUTHORIZATION"] = $valor;
                break;
            }
        }
    }
}
