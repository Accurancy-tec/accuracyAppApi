<?php

require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/QuizService.php";

function sendQuizResponse(int $status, array $body): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** GET cursos/quiz?id_trilha=N: questions of the trail and the answers of the user. */
function quizQuestionsController(): void
{
    global $conexao;

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        sendQuizResponse(405, ["success" => false, "message" => "Método não permitido. Use GET."]);
    }
    $userId = (int) autenticar();
    $trailId = filter_var($_GET["id_trilha"] ?? null, FILTER_VALIDATE_INT);
    if (!$trailId || $trailId <= 0) {
        sendQuizResponse(400, ["success" => false, "message" => "Trilha inválida."]);
    }

    try {
        $questions = listQuizForUser($conexao, $trailId, $userId);
    } catch (Throwable $error) {
        error_log("Quiz list failed: " . $error->getMessage());
        sendQuizResponse(500, ["success" => false, "message" => "Não foi possível carregar o quiz agora."]);
    }

    $answered = array_filter($questions, static fn(array $question): bool => $question["answered"]);
    sendQuizResponse(200, [
        "success" => true,
        "questions" => $questions,
        "score" => [
            "total" => count($questions),
            "answered" => count($answered),
            "correct" => count(array_filter($answered, static fn(array $question): bool => $question["is_correct"] === true)),
        ],
    ]);
}

/** POST cursos/responder-quiz {question_id, option}: saves the answer and reveals the correct option. */
function answerQuizController(): void
{
    global $conexao;

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendQuizResponse(405, ["success" => false, "message" => "Método não permitido. Use POST."]);
    }
    $userId = (int) autenticar();
    $body = json_decode(file_get_contents("php://input"), true);
    $body = is_array($body) ? $body : [];
    $questionId = filter_var($body["question_id"] ?? null, FILTER_VALIDATE_INT);
    $option = filter_var($body["option"] ?? null, FILTER_VALIDATE_INT);
    if (!$questionId || $option === false || $option === null) {
        sendQuizResponse(400, ["success" => false, "message" => "Escolha uma alternativa."]);
    }

    try {
        $result = answerQuizQuestion($conexao, $userId, $questionId, $option);
    } catch (QuizValidationException $error) {
        sendQuizResponse(400, ["success" => false, "message" => $error->getMessage()]);
    } catch (Throwable $error) {
        error_log("Quiz answer failed: " . $error->getMessage());
        sendQuizResponse(500, ["success" => false, "message" => "Não foi possível salvar a resposta agora."]);
    }
    if ($result === null) {
        sendQuizResponse(404, ["success" => false, "message" => "Pergunta não encontrada."]);
    }

    sendQuizResponse(200, ["success" => true] + $result);
}
