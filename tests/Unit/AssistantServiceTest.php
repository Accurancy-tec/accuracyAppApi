<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Services/AssistantService.php';

final class AssistantServiceTest extends TestCase
{
    private function asset(string $symbol, string $category, float $value, bool $simulated = false): array
    {
        return [
            'simbolo' => $symbol,
            'categoria' => $category,
            'simulada' => $simulated,
            'valor_atual' => $value,
            'rentabilidade_percentual' => 0.0,
        ];
    }

    private function trail(int $id, string $title, int $completed, int $total): array
    {
        return [mb_strtolower($title, 'UTF-8') => ['trail_id' => $id, 'trail' => $title, 'completed' => $completed, 'total' => $total]];
    }

    public function testNoAssetsHasNoScoreAndNoRisk(): void
    {
        $composition = assistantComposition([]);

        $this->assertSame(0, $composition['asset_count']);
        $this->assertNull(assistantDiversification($composition, 'nenhuma carteira')['score']);
        $this->assertNull(assistantRisk($composition)['level']);
    }

    public function testSingleAssetIsConcentratedAndAggressive(): void
    {
        $composition = assistantComposition([$this->asset('PETR4', 'Ações', 1000)]);

        $diversification = assistantDiversification($composition, 'carteiras reais');
        $risk = assistantRisk($composition);

        $this->assertSame(0, $diversification['score']);
        $this->assertSame('Concentrada', $diversification['label']);
        $this->assertSame(4.0, $risk['level']);
        $this->assertSame('Arrojado', $risk['label']);
        $this->assertSame('PETR4', $composition['largest_asset']['symbol']);
    }

    public function testHalfFixedIncomeHalfStocksIsModerate(): void
    {
        $composition = assistantComposition([
            $this->asset('CDB', 'Renda Fixa', 500),
            $this->asset('LCI', 'Renda Fixa', 500),
            $this->asset('ITUB4', 'Ações', 500),
            $this->asset('VALE3', 'Ações', 500),
        ]);

        $this->assertEqualsWithDelta(0.25, $composition['asset_hhi'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $composition['category_hhi'], 1e-9);
        $this->assertSame(63, assistantDiversification($composition, 'carteiras reais')['score']);
        $this->assertSame(['level' => 2.5, 'label' => 'Moderado'], assistantRisk($composition));
    }

    public function testManyAssetsAcrossCategoriesAreDiversified(): void
    {
        $assets = [];
        foreach (['Renda Fixa', 'Ações', 'FIIs', 'Internacional'] as $index => $category) {
            for ($i = 0; $i < 3; $i++) {
                $assets[] = $this->asset("A{$index}{$i}", $category, 100);
            }
        }

        $diversification = assistantDiversification(assistantComposition($assets), 'carteiras reais');

        $this->assertGreaterThanOrEqual(65, $diversification['score']);
        $this->assertSame('Diversificada', $diversification['label']);
    }

    public function testRecommendsAtMostTwoUnfinishedTrailsInPriorityOrder(): void
    {
        $trails = $this->trail(1, 'Iniciante', 0, 5)
            + $this->trail(2, 'Renda Fixa', 1, 4)
            + $this->trail(3, 'Cripto', 0, 3);
        $composition = assistantComposition([$this->asset('BTC', 'Cripto', 300)]);

        $study = assistantRecommendations($trails, $composition, 1, false);

        $this->assertSame([1, 3], array_column($study, 'trail_id'));
        $this->assertNotEmpty($study[0]['reason']);
    }

    public function testFinishedTrailsAreNotRecommended(): void
    {
        $trails = $this->trail(1, 'Iniciante', 5, 5) + $this->trail(2, 'Renda Fixa', 4, 4);
        $composition = assistantComposition([$this->asset('PETR4', 'Ações', 300)]);

        $this->assertSame([], assistantRecommendations($trails, $composition, 10, true));
    }

    public function testHabitsTextMentionsIdleCashAndSelic(): void
    {
        $text = assistantHabitsText(0, 30, 80.0, [], ['selic' => 15.0, 'cdi' => 14.9]);

        $this->assertStringContainsString('Não houve compras nem vendas nos últimos 30 dias.', $text);
        $this->assertStringContainsString('80% do patrimônio real está como saldo disponível', $text);
        $this->assertStringContainsString('Selic está em 15% ao ano e o CDI perto de 14,9%', $text);
    }

    public function testPercentFormatting(): void
    {
        $this->assertSame('3,42%', formatPercent(3.42));
        $this->assertSame('78,5%', formatPercent(78.5));
        $this->assertSame('60%', formatPercent(60));
        $this->assertSame('-2%', formatPercent(-2, true));
        $this->assertSame('+1,5%', formatPercent(1.5, true));
    }
}
