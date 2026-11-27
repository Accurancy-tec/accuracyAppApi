<?php

// Course quiz: multiple choice questions attached to a trail. The correct option of a question
// is only revealed to the user after they answer it.

const QUIZ_MIN_OPTIONS = 2;
const QUIZ_MAX_OPTIONS = 5;

final class QuizValidationException extends InvalidArgumentException
{
}

/** Normalizes a question sent by the admin; throws QuizValidationException with the message to show. */
function validateQuizQuestion(array $body): array
{
    $trailId = filter_var($body["trail_id"] ?? null, FILTER_VALIDATE_INT);
    $question = trim((string) ($body["question"] ?? ""));
    $rawOptions = is_array($body["options"] ?? null) ? $body["options"] : [];
    $options = array_values(array_filter(
        array_map(static fn($option): string => trim((string) $option), $rawOptions),
        static fn(string $option): bool => $option !== ""
    ));
    $correct = filter_var($body["correct_option"] ?? null, FILTER_VALIDATE_INT);
    $explanation = trim((string) ($body["explanation"] ?? ""));

    if (!$trailId || $trailId <= 0) {
        throw new QuizValidationException("Escolha a trilha da pergunta.");
    }
    if (mb_strlen($question, "UTF-8") < 5 || mb_strlen($question, "UTF-8") > 500) {
        throw new QuizValidationException("Escreva a pergunta (de 5 a 500 caracteres).");
    }
    if (count($options) < QUIZ_MIN_OPTIONS || count($options) > QUIZ_MAX_OPTIONS) {
        throw new QuizValidationException("Informe de " . QUIZ_MIN_OPTIONS . " a " . QUIZ_MAX_OPTIONS . " alternativas.");
    }
    foreach ($options as $option) {
        if (mb_strlen($option, "UTF-8") > 200) {
            throw new QuizValidationException("Cada alternativa pode ter até 200 caracteres.");
        }
    }
    if ($correct === false || $correct === null || $correct < 0 || $correct >= count($options)) {
        throw new QuizValidationException("Marque qual alternativa é a correta.");
    }
    if (mb_strlen($explanation, "UTF-8") > 500) {
        throw new QuizValidationException("A explicação pode ter até 500 caracteres.");
    }

    return [
        "trail_id" => $trailId,
        "question" => $question,
        "options" => $options,
        "correct_option" => $correct,
        "explanation" => $explanation === "" ? null : $explanation,
    ];
}

/** Questions of a trail with the answer of the user (correct option only for answered ones). */
function listQuizForUser(PDO $connection, int $trailId, int $userId): array
{
    $statement = $connection->prepare("
        SELECT q.question_id, q.question, q.options, q.correct_option, q.explanation,
               a.chosen_option, a.is_correct
          FROM quiz_question q
          LEFT JOIN quiz_answer a ON a.question_id = q.question_id AND a.user_id = :user_id
         WHERE q.trail_id = :trail_id
         ORDER BY q.question_id
    ");
    $statement->execute([":trail_id" => $trailId, ":user_id" => $userId]);

    $questions = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $answered = $row["chosen_option"] !== null;
        $questions[] = [
            "question_id" => (int) $row["question_id"],
            "question" => $row["question"],
            "options" => json_decode($row["options"], true),
            "answered" => $answered,
            "chosen_option" => $answered ? (int) $row["chosen_option"] : null,
            "is_correct" => $answered ? in_array($row["is_correct"], [true, "t", 1], true) : null,
            "correct_option" => $answered ? (int) $row["correct_option"] : null,
            "explanation" => $answered ? $row["explanation"] : null,
        ];
    }
    return $questions;
}

/** Saves (or replaces) the answer of the user. Returns null when the question does not exist. */
function answerQuizQuestion(PDO $connection, int $userId, int $questionId, int $chosenOption): ?array
{
    $statement = $connection->prepare("SELECT options, correct_option, explanation FROM quiz_question WHERE question_id = :question_id");
    $statement->execute([":question_id" => $questionId]);
    $question = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$question) {
        return null;
    }
    if ($chosenOption < 0 || $chosenOption >= count(json_decode($question["options"], true))) {
        throw new QuizValidationException("Alternativa inválida.");
    }

    $isCorrect = $chosenOption === (int) $question["correct_option"];
    $connection->prepare("
        INSERT INTO quiz_answer (user_id, question_id, chosen_option, is_correct)
        VALUES (:user_id, :question_id, :chosen, :correct)
        ON CONFLICT (user_id, question_id)
        DO UPDATE SET chosen_option = EXCLUDED.chosen_option, is_correct = EXCLUDED.is_correct, answered_at = NOW()
    ")->execute([
        ":user_id" => $userId,
        ":question_id" => $questionId,
        ":chosen" => $chosenOption,
        ":correct" => $isCorrect ? "true" : "false",
    ]);

    return [
        "is_correct" => $isCorrect,
        "correct_option" => (int) $question["correct_option"],
        "explanation" => $question["explanation"],
    ];
}

/** Every question of a trail, with the correct option and how many users answered (admin). */
function listQuizForAdmin(PDO $connection, int $trailId): array
{
    $statement = $connection->prepare("
        SELECT q.question_id, q.question, q.options, q.correct_option, q.explanation,
               COUNT(a.user_id) AS answers,
               COUNT(a.user_id) FILTER (WHERE a.is_correct) AS correct_answers
          FROM quiz_question q
          LEFT JOIN quiz_answer a ON a.question_id = q.question_id
         WHERE q.trail_id = :trail_id
         GROUP BY q.question_id
         ORDER BY q.question_id
    ");
    $statement->execute([":trail_id" => $trailId]);

    return array_map(static fn(array $row): array => [
        "question_id" => (int) $row["question_id"],
        "question" => $row["question"],
        "options" => json_decode($row["options"], true),
        "correct_option" => (int) $row["correct_option"],
        "explanation" => $row["explanation"],
        "answers" => (int) $row["answers"],
        "correct_answers" => (int) $row["correct_answers"],
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

function createQuizQuestion(PDO $connection, array $question, int $adminId): int
{
    $statement = $connection->prepare("
        INSERT INTO quiz_question (trail_id, question, options, correct_option, explanation, created_by)
        VALUES (:trail_id, :question, CAST(:options AS jsonb), :correct, :explanation, :admin_id)
        RETURNING question_id
    ");
    $statement->execute([
        ":trail_id" => $question["trail_id"],
        ":question" => $question["question"],
        ":options" => json_encode($question["options"], JSON_UNESCAPED_UNICODE),
        ":correct" => $question["correct_option"],
        ":explanation" => $question["explanation"],
        ":admin_id" => $adminId,
    ]);
    return (int) $statement->fetchColumn();
}

function deleteQuizQuestion(PDO $connection, int $questionId): bool
{
    $statement = $connection->prepare("DELETE FROM quiz_question WHERE question_id = :question_id");
    $statement->execute([":question_id" => $questionId]);
    return $statement->rowCount() > 0;
}
