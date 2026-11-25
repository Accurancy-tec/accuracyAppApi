<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Repositories/AporteRepository.php';

final class PosicaoPorOperacoesTest extends TestCase
{
    private function operacao(string $tipo, float $quantidade, float $valor): array
    {
        return ['tipo_aporte' => $tipo, 'quantidade_aporte' => $quantidade, 'valor_aporte' => $valor];
    }

    public function testPrecoMedioPonderadoIgnoraVendas(): void
    {
        $posicao = calcularPosicaoPorOperacoes([
            $this->operacao('Compra', 10, 100),
            $this->operacao('Venda', 5, 80),
            $this->operacao('Compra', 5, 100),
        ]);

        // Sobram 5 a R$ 10 + 5 a R$ 20 => 10 cotas a R$ 15
        $this->assertSame(10.0, $posicao['quantidade']);
        $this->assertEqualsWithDelta(15.0, $posicao['preco_medio'], 0.0001);
    }

    public function testDividendoNaoMudaQuantidade(): void
    {
        $posicao = calcularPosicaoPorOperacoes([
            $this->operacao('Compra', 10, 100),
            $this->operacao('Dividendo', 0, 5),
        ]);

        $this->assertSame(10.0, $posicao['quantidade']);
        $this->assertEqualsWithDelta(10.0, $posicao['preco_medio'], 0.0001);
    }

    public function testVendaSemCotasSuficientesInvalidaAsOperacoes(): void
    {
        // Excluir a compra deixaria uma venda sem cotas: a exclusão precisa ser bloqueada.
        $this->assertNull(calcularPosicaoPorOperacoes([$this->operacao('Venda', 1, 10)]));
    }

    public function testVenderTudoZeraAPosicao(): void
    {
        $posicao = calcularPosicaoPorOperacoes([
            $this->operacao('Compra', 3, 30),
            $this->operacao('Venda', 3, 33),
        ]);

        $this->assertSame(0.0, $posicao['quantidade']);
        $this->assertSame(0.0, $posicao['preco_medio']);
    }
}
