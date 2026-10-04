<?php
header('Content-Type: application/json; charset=UTF-8');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$partes = explode('/', trim($uri, '/'));

switch (true) {

    case $partes[0] === 'auth':
        require __DIR__ . '/../routes/auth.php';
        break;

    case $partes[0] === 'aportes':
        require __DIR__ . '/../routes/aportes.php';
        break;

    case $partes[0] === 'carteiras':
        require __DIR__ . '/../routes/carteira.php';
        break;

    case $partes[0] === 'brapi':
        require __DIR__ . '/../routes/brapi.php';
        break;

    case $partes[0] === 'vico':
        require __DIR__ . '/../routes/vico.php';
        break;
    
    case $partes[0] === 'automacao':
        require __DIR__ . '/../routes/automacao.php';
        break;

    default:

        http_response_code(404);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Rota não encontrada'
        ]);
}
