```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../service/ativoService.php";
require_once __DIR__ . "/../service/posicaoService.php";
require_once __DIR__ . "/../service/carteiraService.php";

final class PosicaoServiceTest extends TestCase
{
    private PDO $conexao;
    private string $simboloTeste;
    private int $idAtivo;
    private int $idCarteira;
    public function setUp(): void
    {
        parent::setUp();

        $this->conexao = require __DIR__ . "/../database/conexao.php";

        // Símbolo exclusivo para cada teste
        $this->simboloTeste = 'TEST_' . time();

        // Cria o ativo que será utilizado pelos testes
        $this->idAtivo = buscarOuCriarAtivo(
            $this->conexao,
            $this->simboloTeste,
            "Ativo de teste",
            "Ações"
        );

        $this->idCarteira = criarCarteira(
            $this->conexao,
            1,
            'TIME_' . time(),
            "Simulada",
            10000
        );
    }

    public function tearDown(): void
    {
        // Remove a posição criada pelo teste
        $sql = "DELETE FROM item_investimento
                WHERE id_carteira = ? AND id_ativo = ?";

        $delete = $this->conexao->prepare($sql);
        $delete->execute([
            $this->idCarteira,
            $this->idAtivo
        ]);

        // Remove a carteira criada pelo teste
        $sql = "DELETE FROM carteira
                WHERE id_carteira = ?";

        $delete = $this->conexao->prepare($sql);
        $delete->execute([
            $this->idCarteira
        ]);

        // Remove o ativo criado pelo teste
        $sql = "DELETE FROM ativo
                WHERE id_ativo = ?";

        $delete = $this->conexao->prepare($sql);
        $delete->execute([
            $this->idAtivo
        ]);

        parent::tearDown();
    }

      //Testa a criação de uma nova posição através de uma compra.
    public function testCriarPosicaoComCompra(): void
    {
        $resultado = atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'compra',
            10,
            100
        );

        $this->assertTrue($resultado);

        $sql = "SELECT quantidade_item, preco_medio_item
                FROM item_investimento
                WHERE id_carteira = ? AND id_ativo = ?";

        $consulta = $this->conexao->prepare($sql);
        $consulta->execute([
            $this->idCarteira,
            $this->idAtivo
        ]);

        $posicao = $consulta->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($posicao);

        $this->assertEquals(
            10,
            $posicao['quantidade_item']
        );

        $this->assertEquals(
            10,
            $posicao['preco_medio_item']
        );
    }

     //Testa uma segunda compra do mesmo ativo.
    public function testAdicionarCompraNaPosicaoExistente(): void
    {
        // Primeira compra:
        // 10 unidades por R$100
        atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'compra',
            10,
            100
        );

        // Segunda compra:
        // 10 unidades por R$200
        $resultado = atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'compra',
            10,
            200
        );

        $this->assertTrue($resultado);

        $sql = "SELECT quantidade_item, preco_medio_item
                FROM item_investimento
                WHERE id_carteira = ? AND id_ativo = ?";

        $consulta = $this->conexao->prepare($sql);
        $consulta->execute([
            $this->idCarteira,
            $this->idAtivo
        ]);

        $posicao = $consulta->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($posicao);

        // 10 + 10 = 20 unidades
        $this->assertEquals(
            20,
            $posicao['quantidade_item']
        );

        // (10 * 10 + 10 * 20) / 20 = 15
        $this->assertEquals(
            15,
            $posicao['preco_medio_item']
        );
    }

     //Testa a venda de parte da posição.
    public function testVendaReduzQuantidade(): void
    {
        // Compra 10 unidades
        atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'compra',
            10,
            100
        );

        // Vende 4 unidades
        $resultado = atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'venda',
            4,
            40
        );

        $this->assertTrue($resultado);

        $sql = "SELECT quantidade_item
                FROM item_investimento
                WHERE id_carteira = ? AND id_ativo = ?";

        $consulta = $this->conexao->prepare($sql);
        $consulta->execute([
            $this->idCarteira,
            $this->idAtivo
        ]);

        $posicao = $consulta->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($posicao);

        // 10 - 4 = 6
        $this->assertEquals(
            6,
            $posicao['quantidade_item']
        );
    }

     //Testa a venda de toda a posição.
    public function testVendaExatamenteTodoSaldo(): void
    {
        // Compra 10 unidades
        atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'compra',
            10,
            100
        );

        // Vende exatamente as 10 unidades
        $resultado = atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'venda',
            10,
            100
        );

        $this->assertTrue($resultado);

        $sql = "SELECT quantidade_item
                FROM item_investimento
                WHERE id_carteira = ? AND id_ativo = ?";

        $consulta = $this->conexao->prepare($sql);
        $consulta->execute([
            $this->idCarteira,
            $this->idAtivo
        ]);

        $posicao = $consulta->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($posicao);

        // O saldo deve ficar em zero
        $this->assertEquals(
            0,
            $posicao['quantidade_item']
        );
    }

     //Testa o erro ao tentar vender mais unidades do que existem na posição.

    public function testVendaMaiorQueSaldoRetornaErro(): void
    {
        // Compra 10 unidades
        atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'compra',
            10,
            100
        );

        // Tenta vender 20 unidades
        $resultado = atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'venda',
            20,
            200
        );

        // Deve retornar false
        $this->assertFalse($resultado);
    }

    //Testa se uma venda inválida não altera o saldo.
    public function testVendaMaiorQueSaldoNaoAlteraPosicao(): void
    {
        // Compra 10 unidades
        atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'compra',
            10,
            100
        );

        // Tenta vender 20 unidades
        $resultado = atualizarPosicao(
            $this->conexao,
            $this->idCarteira,
            $this->idAtivo,
            'venda',
            20,
            200
        );

        $this->assertFalse($resultado);

        // Consulta a posição depois da tentativa
        $sql = "SELECT quantidade_item, preco_medio_item
                FROM item_investimento
                WHERE id_carteira = ? AND id_ativo = ?";

        $consulta = $this->conexao->prepare($sql);
        $consulta->execute([
            $this->idCarteira,
            $this->idAtivo
        ]);

        $posicao = $consulta->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($posicao);

        // A posição continua igual
        $this->assertEquals(
            10,
            $posicao['quantidade_item']
        );

        $this->assertEquals(
            10,
            $posicao['preco_medio_item']
        );
    }
}
?>