<?php

require_once __DIR__ . "/AdminController.php";
require_once __DIR__ . "/../Services/QuizService.php";

/** POST auth/adminListarQuiz {trail_id} */
function adminListQuizController(): void
{
    global $conexao;
    adminAutenticar();

    $trailId = filter_var(adminEntrada()["trail_id"] ?? null, FILTER_VALIDATE_INT);
    if (!$trailId || $trailId <= 0) {
        adminResponder(400, ["success" => false, "message" => "Escolha uma trilha."]);
    }
    adminResponder(200, ["success" => true, "questions" => listQuizForAdmin($conexao, $trailId)]);
}

/** POST auth/adminCriarQuiz {trail_id, question, options[], correct_option, explanation} */
function adminCreateQuizController(): void
{
    global $conexao;
    $admin = adminAutenticar();

    try {
        $question = validateQuizQuestion(adminEntrada());
        createQuizQuestion($conexao, $question, (int) $admin["id_administrador"]);
    } catch (QuizValidationException $error) {
        adminResponder(400, ["success" => false, "message" => $error->getMessage()]);
    } catch (PDOException $error) {
        error_log("Quiz question not saved: " . $error->getMessage());
        adminResponder(400, ["success" => false, "message" => "Não foi possível salvar a pergunta (confira a trilha)."]);
    }

    adminResponder(201, ["success" => true, "message" => "Pergunta adicionada ao quiz."]);
}

/** POST auth/adminExcluirQuiz {question_id} */
function adminDeleteQuizController(): void
{
    global $conexao;
    adminAutenticar();

    $questionId = filter_var(adminEntrada()["question_id"] ?? null, FILTER_VALIDATE_INT);
    if (!$questionId || !deleteQuizQuestion($conexao, $questionId)) {
        adminResponder(404, ["success" => false, "message" => "Pergunta não encontrada."]);
    }
    adminResponder(200, ["success" => true, "message" => "Pergunta excluída."]);
}
