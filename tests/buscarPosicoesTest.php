<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../service/ativoService.php";
require_once __DIR__ . "/../service/posicaoService.php";
require_once __DIR__ . "/../service/carteiraService.php";

final class BuscarPosicoesTest extends TestCase
{
    private PDO $conexao;
    private string $simboloTeste;
    private int $idAtivo;
    private int $idCarteira;

    public function setUp(): void
    {
        parent::setUp();

        $this->conexao = require __DIR__ . "/../database/conexao.php";

        // Símbolo curto e exclusivo
        $this->simboloTeste = 'TEST_' . time();

        // Cria o ativo de teste
        $this->idAtivo = buscarOuCriarAtivo(
            $this->conexao,
            $this->simboloTeste,
            "Ativo de teste",
            "Ações"
        );

        // Cria a carteira de teste
        $this->idCarteira = criarCarteira(
            $this->conexao,
            1,
            'TEST_' . time(),
            "Simulada",
            10000
        );

        // Cria uma posição para ser encontrada
        atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'compra',
            10,
            100
        );
    }

    public function tearDown(): void
    {
        // Remove a posição
        $sql = "DELETE FROM item_investimento
                WHERE id_carteira = ? AND id_ativo = ?";

        $delete = $this->conexao->prepare($sql);
        $delete->execute([
            $this->idCarteira,
            $this->idAtivo
        ]);

        // Remove a carteira
        $sql = "DELETE FROM carteira
                WHERE id_carteira = ?";

        $delete = $this->conexao->prepare($sql);
        $delete->execute([
            $this->idCarteira
        ]);

        // Remove o ativo
        $sql = "DELETE FROM ativo
                WHERE id_ativo = ?";

        $delete = $this->conexao->prepare($sql);
        $delete->execute([
            $this->idAtivo
        ]);

        parent::tearDown();
    }

    /*
    Testa se buscarPosicoes() retorna as posições
    da carteira corretamente.
     */
    public function testBuscarPosicoes(): void
    {
        $posicoes = buscarPosicoes(
            $this->conexao,
            $this->idCarteira
        );

        $this->assertIsArray($posicoes);

        // Deve existir exatamente uma posição criada pelo teste
        $this->assertCount(1, $posicoes);

        $posicao = $posicoes[0];

        $this->assertEquals(
            $this->idCarteira,
            $posicao['id_carteira']
        );

        $this->assertEquals(
            $this->idAtivo,
            $posicao['id_ativo']
        );

        $this->assertEquals(
            $this->simboloTeste,
            $posicao['simbolo_ativo']
        );

        $this->assertEquals(
            'Ativo de teste',
            $posicao['nome_ativo']
        );

        $this->assertEquals(
            10,
            $posicao['quantidade_item']
        );

        $this->assertEquals(
            10,
            $posicao['preco_medio_item']
        );

        $this->assertArrayHasKey(
            'atualizado_em',
            $posicao
        );
    }
}
?>