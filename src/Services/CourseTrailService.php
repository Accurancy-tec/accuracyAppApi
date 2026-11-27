<?php

// Users watch courses inside trails; a course belongs to the trail whose title matches its category.

/** Puts the course at the end of the trail of its category, leaving any other trail. */
function placeCourseInCategoryTrail(PDO $connection, int $courseId): void
{
    $connection->prepare("
        DELETE FROM atividade a
         USING curso c, trilha t
         WHERE a.id_curso = :course_id
           AND c.id_curso = a.id_curso
           AND t.id_trilha = a.id_trilha
           AND lower(t.titulo_trilha) <> lower(c.categoria_curso::text)
    ")->execute([":course_id" => $courseId]);

    $connection->prepare("
        INSERT INTO atividade (id_trilha, id_curso, ordem_atividade)
        SELECT t.id_trilha, c.id_curso,
               COALESCE((SELECT MAX(ordem_atividade) FROM atividade WHERE id_trilha = t.id_trilha), 0) + 1
          FROM curso c
          JOIN trilha t ON lower(t.titulo_trilha) = lower(c.categoria_curso::text)
         WHERE c.id_curso = :course_id
        ON CONFLICT (id_trilha, id_curso) DO NOTHING
    ")->execute([":course_id" => $courseId]);
}

/** Removes the course from every trail (the progress of its lessons goes with it). */
function removeCourseFromTrails(PDO $connection, int $courseId): void
{
    $connection->prepare("DELETE FROM atividade WHERE id_curso = :course_id")->execute([":course_id" => $courseId]);
}
