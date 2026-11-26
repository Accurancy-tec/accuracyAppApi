<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Repositories/CarteiraRepository.php';
require_once __DIR__ . '/../../src/Repositories/AporteRepository.php';
require_once __DIR__ . '/../../src/Repositories/AtivoRepository.php';
require_once __DIR__ . '/../../src/Repositories/PosicaoRepository.php';

/**
 * Regras de saldo que dependem do banco (trigger atualizar_saldo_usuario + estorno na exclusão).
 * Roda só com DB_HOST local; precisa do banco com o saldo próprio da carteira simulada (capital_simulado_carteira e o trigger de saldo atualizado).
 */
final class SaldoCarteirasTest extends TestCase
{
    private PDO $conexao;
    private int $idUsuario;
    private int $idAtivo;

    protected function setUp(): void
    {
        $conexao = conexaoBancoDeTeste();

        if ($conexao === null) {
            $this->markTestSkipped('Defina DB_HOST com um banco local para rodar os testes de integração.');
        }

        $this->conexao = $conexao;
        $sufixo = bin2hex(random_bytes(4));

        $this->idUsuario = (int) $conexao->query(
            "INSERT INTO usuario (nome_usuario, email_usuario, saldo)
             VALUES ('Teste integração', 'integracao-$sufixo@accuracy.test', 1000)
             RETURNING id_usuario"
        )->fetchColumn();

        $this->idAtivo = (int) buscarOuCriarAtivo($conexao, 'TI' . strtoupper($sufixo), 'Ativo de teste', 'Ações');
    }

    protected function tearDown(): void
    {
        if (!isset($this->conexao)) {
            return;
        }

        $this->conexao->prepare('DELETE FROM transacao_saldo WHERE id_usuario = ?')->execute([$this->idUsuario]);
        $this->conexao->prepare('DELETE FROM usuario WHERE id_usuario = ?')->execute([$this->idUsuario]);
        $this->conexao->prepare('DELETE FROM ativo WHERE id_ativo = ?')->execute([$this->idAtivo]);
    }

    private function saldoGeral(): float
    {
        $stmt = $this->conexao->prepare('SELECT saldo FROM usuario WHERE id_usuario = ?');
        $stmt->execute([$this->idUsuario]);

        return (float) $stmt->fetchColumn();
    }

    private function saldoCarteira(int $idCarteira): float
    {
        $stmt = $this->conexao->prepare('SELECT saldo_livre_carteira FROM carteira WHERE id_carteira = ?');
        $stmt->execute([$idCarteira]);

        return (float) $stmt->fetchColumn();
    }

    private function comprar(int $idCarteira, float $quantidade, float $valor): int
    {
        $this->conexao->beginTransaction();
        $id = (int) criarAporte($this->conexao, $idCarteira, $this->idAtivo, 'Compra', $quantidade, $valor, 'Único', null, date('Y-m-d'))[0]['id_aporte'];
        atualizarPosicao($this->conexao, $idCarteira, $this->idAtivo, 'Compra', $quantidade, $valor);
        $this->conexao->commit();

        return $id;
    }

    public function testCompraNaSimuladaUsaSoOSaldoDaSimulacao(): void
    {
        $simulada = (int) registrarCarteira($this->conexao, $this->idUsuario, 'Simulação', 'Simulada', 'Mista', 0);
        depositarNaCarteiraSimulada($this->conexao, $this->idUsuario, $simulada, 5000, 'Saldo inicial da simulação');

        $this->comprar($simulada, 10, 300);

        $this->assertSame(4700.0, $this->saldoCarteira($simulada));
        $this->assertSame(1000.0, $this->saldoGeral());
    }

    public function testCompraNaRealUsaSaldoGeralEBloqueiaSemSaldo(): void
    {
        $real = (int) registrarCarteira($this->conexao, $this->idUsuario, 'Principal', 'Real', 'Mista', 0);

        $this->comprar($real, 10, 300);
        $this->assertSame(700.0, $this->saldoGeral());

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Saldo insuficiente');

        try {
            $this->comprar($real, 100, 3000);
        } finally {
            if ($this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }
        }
    }

    public function testExcluirCompraDevolveOSaldoERemoveAPosicao(): void
    {
        $real = (int) registrarCarteira($this->conexao, $this->idUsuario, 'Principal', 'Real', 'Mista', 0);
        $idCompra = $this->comprar($real, 10, 300);

        $this->assertTrue(excluirAporteRepository($this->conexao, $idCompra, $this->idUsuario));

        $this->assertSame(1000.0, $this->saldoGeral());
        $this->assertSame(0, contarPosicoesAbertas($this->conexao, $real));
    }

    public function testNaoExcluiCompraQueJaFoiVendida(): void
    {
        $real = (int) registrarCarteira($this->conexao, $this->idUsuario, 'Principal', 'Real', 'Mista', 0);
        $idCompra = $this->comprar($real, 10, 300);

        $this->conexao->beginTransaction();
        criarAporte($this->conexao, $real, $this->idAtivo, 'Venda', 10, 320, 'Único', null, date('Y-m-d'));
        atualizarPosicao($this->conexao, $real, $this->idAtivo, 'Venda', 10, 320);
        $this->conexao->commit();

        $this->expectException(AporteValidacaoException::class);
        excluirAporteRepository($this->conexao, $idCompra, $this->idUsuario);
    }

    public function testNaoMexeEmAporteDeOutroUsuario(): void
    {
        $real = (int) registrarCarteira($this->conexao, $this->idUsuario, 'Principal', 'Real', 'Mista', 0);
        $idCompra = $this->comprar($real, 1, 30);

        $this->assertFalse(excluirAporteRepository($this->conexao, $idCompra, $this->idUsuario + 100000));
        $this->assertSame(970.0, $this->saldoGeral());
    }
}
