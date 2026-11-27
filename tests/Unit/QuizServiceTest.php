<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Services/QuizService.php';

final class QuizServiceTest extends TestCase
{
    private function question(array $overrides = []): array
    {
        return $overrides + [
            'trail_id' => 2,
            'question' => 'O que é o CDI?',
            'options' => ['Uma taxa de referência', 'Uma ação', ''],
            'correct_option' => 0,
            'explanation' => '  ',
        ];
    }

    public function testEmptyOptionsAreDroppedAndExplanationBecomesNull(): void
    {
        $question = validateQuizQuestion($this->question());

        $this->assertSame(['Uma taxa de referência', 'Uma ação'], $question['options']);
        $this->assertNull($question['explanation']);
        $this->assertSame(0, $question['correct_option']);
    }

    public function testCorrectOptionMustPointToAnExistingAlternative(): void
    {
        $this->expectException(QuizValidationException::class);
        validateQuizQuestion($this->question(['correct_option' => 2]));
    }

    public function testNeedsAtLeastTwoAlternatives(): void
    {
        $this->expectException(QuizValidationException::class);
        validateQuizQuestion($this->question(['options' => ['Só uma']]));
    }

    public function testNeedsATrail(): void
    {
        $this->expectException(QuizValidationException::class);
        validateQuizQuestion($this->question(['trail_id' => 0]));
    }
}
