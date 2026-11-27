<?php
require_once __DIR__ . '/../src/Controllers/CursoController.php';

$acao = $partes[1] ?? null;

// Accuracy: rotas adicionais de atividades e progresso.
// As rotas originais continuam abaixo, com suas regras intactas.
if (in_array($acao, ['atividades', 'progresso', 'salvar-progresso'], true)) {
    require_once __DIR__ . '/../src/Controllers/ProgressoController.php';
    accuracyCursosProgressoController($acao);
    return;
}

if ($acao === 'quiz' || $acao === 'responder-quiz') {
    require_once __DIR__ . '/../src/Controllers/QuizController.php';
    $acao === 'quiz' ? quizQuestionsController() : answerQuizController();
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);

    exit;
}

switch ($acao) {

    case 'trilhas':
        listarTrilhasController();
        break;

    case 'trilha':
        buscarTrilhaController();
        break;

    default:
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Ação não encontrada'
        ]);
        break;
}
