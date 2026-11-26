<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Services/CalculoPatrimonioService.php';

final class CalculoPatrimonioTest extends TestCase
{
    private function posicao(string $simbolo, float $quantidade, float $precoMedio, float $precoAtual, string $categoria = 'Ações', string $origem = 'mercado'): array
    {
        return [
            'id_ativo' => crc32($simbolo),
            'simbolo_ativo' => $simbolo,
            'nome_ativo' => $simbolo,
            'categoria_ativo' => $categoria,
            'quantidade_item' => $quantidade,
            'preco_medio_item' => $precoMedio,
            'preco_atual' => $precoAtual,
            'origem_preco' => $origem,
        ];
    }

    private function carteira(string $tipo, float $saldoLivre = 0, float $capital = 0, float $meta = 0): array
    {
        return [
            'id_carteira' => 1,
            'nome_carteira' => 'Teste',
            'tipo_carteira' => $tipo,
            'estilo_carteira' => 'Mista',
            'meta_carteira' => $meta,
            'saldo_livre_carteira' => $saldoLivre,
            'capital_simulado_carteira' => $capital,
            'total_aportado' => 0,
        ];
    }

    public function testCarteiraRealSomaCadaAtivoUmaUnicaVez(): void
    {
        $resultado = consolidarCarteira($this->carteira('Real'), [
            $this->posicao('PETR4', 10, 30, 33),
            $this->posicao('MXRF11', 100, 10, 9.5, 'FIIs'),
        ]);

        // Custo: 10 x 30 + 100 x 10 = 1.300 (antes era somado duas vezes)
        $this->assertSame(1300.0, $resultado['total_investido']);
        $this->assertSame(1280.0, $resultado['valor_mercado']);
        $this->assertSame(1280.0, $resultado['patrimonio_atual']);
        $this->assertSame(-20.0, $resultado['lucro_prejuizo']);
        $this->assertSame(-1.54, $resultado['rentabilidade_percentual']);
        $this->assertArrayNotHasKey('capital_simulado', $resultado);
    }

    public function testCarteiraRealIgnoraSaldoLivreNoPatrimonio(): void
    {
        // Na carteira real o dinheiro livre é o saldo geral do usuário, não da carteira.
        $resultado = consolidarCarteira($this->carteira('Real', 500), [$this->posicao('PETR4', 1, 30, 30)]);

        $this->assertSame(30.0, $resultado['patrimonio_atual']);
        $this->assertSame(0.0, $resultado['saldo_livre_carteira']);
    }

    public function testCarteiraSimuladaUsaSaldoECapitalProprios(): void
    {
        $resultado = consolidarCarteira(
            $this->carteira('Simulada', 7000, 10000),
            [$this->posicao('PETR4', 100, 30, 33)]
        );

        // Patrimônio = 3.300 em ações + 7.000 livres; resultado = 10.300 - 10.000 de capital
        $this->assertSame(10300.0, $resultado['patrimonio_atual']);
        $this->assertSame(300.0, $resultado['resultado_simulacao']);
        $this->assertSame(3.0, $resultado['rentabilidade_simulacao']);
        $this->assertSame(10.0, $resultado['rentabilidade_percentual']);
    }

    public function testMetaUsaPatrimonioDaCarteira(): void
    {
        $resultado = consolidarCarteira($this->carteira('Simulada', 600, 1000, 1500), [$this->posicao('PETR4', 10, 40, 45)]);

        $this->assertSame(70.0, $resultado['progresso_meta']);
        $this->assertFalse($resultado['meta_atingida']);
    }

    public function testResultadoRealizadoUsaPrecoMedioDaEpoca(): void
    {
        $operacoes = [
            ['id_carteira' => 1, 'id_ativo' => 5, 'tipo_aporte' => 'Compra', 'quantidade_aporte' => 10, 'valor_aporte' => 100],
            ['id_carteira' => 1, 'id_ativo' => 5, 'tipo_aporte' => 'Compra', 'quantidade_aporte' => 10, 'valor_aporte' => 200],
            ['id_carteira' => 1, 'id_ativo' => 5, 'tipo_aporte' => 'Venda', 'quantidade_aporte' => 5, 'valor_aporte' => 100],
            ['id_carteira' => 1, 'id_ativo' => 5, 'tipo_aporte' => 'Dividendo', 'quantidade_aporte' => 0, 'valor_aporte' => 7.5],
            ['id_carteira' => 2, 'id_ativo' => 5, 'tipo_aporte' => 'Compra', 'quantidade_aporte' => 1, 'valor_aporte' => 50],
        ];

        $resultado = calcularResultadoRealizado($operacoes);

        // Preço médio 15: vender 5 por 100 dá 25 de lucro. A carteira 2 não interfere.
        $this->assertEqualsWithDelta(25.0, $resultado[1]['vendas'], 0.0001);
        $this->assertSame(7.5, $resultado[1]['dividendos']);
        $this->assertSame(0.0, $resultado[2]['vendas']);
    }

    public function testResumoSeparaCarteirasReaisDasSimuladas(): void
    {
        $real = consolidarCarteira($this->carteira('Real'), [$this->posicao('PETR4', 10, 30, 33)]);
        $simulada = consolidarCarteira($this->carteira('Simulada', 5000, 5000), []);

        $resumo = resumirCarteiras([$real, $simulada], 1000);

        $this->assertSame(1330.0, $resumo['real']['patrimonio_total']);
        $this->assertSame(10.0, $resumo['real']['rentabilidade_percentual']);
        $this->assertSame(5000.0, $resumo['simulado']['patrimonio_total']);
        $this->assertSame(0.0, $resumo['simulado']['resultado']);
    }

    public function testDistribuicaoPorCategoria(): void
    {
        $carteira = consolidarCarteira($this->carteira('Real'), [
            $this->posicao('PETR4', 10, 30, 30),
            $this->posicao('MXRF11', 30, 10, 10, 'FIIs'),
        ]);

        $distribuicao = distribuicaoPorCategoria([$carteira]);

        $this->assertSame(['Ações', 'FIIs'], array_column($distribuicao, 'categoria'));
        $this->assertSame([50.0, 50.0], array_column($distribuicao, 'percentual'));
    }

    public function testRendaFixaRendeConformeIndexador(): void
    {
        $indicadores = ['cdi' => 10.0, 'selic' => 10.1, 'ipca' => 4.0];

        $this->assertEqualsWithDelta(1100.0, valorAtualRendaFixa(1000, '2025-01-01', null, 'PRE', 10, $indicadores, '2026-01-01'), 0.01);
        $this->assertEqualsWithDelta(1100.0, valorAtualRendaFixa(1000, '2025-01-01', null, 'CDI', 100, $indicadores, '2026-01-01'), 0.01);
        $this->assertEqualsWithDelta(1092.0, valorAtualRendaFixa(1000, '2025-01-01', null, 'IPCA', 5, $indicadores, '2026-01-01'), 0.01);
    }

    public function testRendaFixaParaDeRenderNoVencimentoESemIndicadorFicaNoCusto(): void
    {
        $noVencimento = valorAtualRendaFixa(1000, '2025-01-01', '2026-01-01', 'PRE', 10, [], '2027-06-01');
        $this->assertEqualsWithDelta(1100.0, $noVencimento, 0.01);

        $this->assertSame(1000.0, valorAtualRendaFixa(1000, '2025-01-01', null, 'CDI', 100, [], '2026-01-01'));
    }
}
