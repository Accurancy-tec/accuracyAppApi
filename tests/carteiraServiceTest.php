<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../service/carteiraService.php";

final class CarteiraServiceTest extends TestCase
{
    private PDO $conexao;
    private string $nomeCarteiraTeste;
    private int $idCarteiraTeste;

    public function setUp(): void
    {
        parent::setUp();

        $this->conexao = require __DIR__ . "/../database/conexao.php";

        $this->nomeCarteiraTeste = 'TEST_' . uniqid();
    }

    public function tearDown(): void
    {
        if (isset($this->idCarteiraTeste)) {
            $sql = "DELETE FROM carteira WHERE id_carteira = ?";

            $del = $this->conexao->prepare($sql);
            $del->execute([$this->idCarteiraTeste]);
        }

        parent::tearDown();
    }

    public function testCriarCarteira(): void
    {
        $idUsuario = 1;
        $tipoCarteira = "Simulada";
        $saldoLivre = "-10.00";

        $idCarteira = criarCarteira(
            $this->conexao,
            $idUsuario,
            $this->nomeCarteiraTeste,
            $tipoCarteira,
            $saldoLivre
        );

        $this->idCarteiraTeste = $idCarteira;

        // Verifica o ID retornado
        $this->assertIsNumeric($idCarteira);
        $this->assertGreaterThan(0, $idCarteira);

        // Busca a carteira criada
        $sql = "SELECT id_carteira,
                       id_usuario,
                       nome_carteira,
                       tipo_carteira,
                       saldo_livre_carteira
                FROM carteira
                WHERE id_carteira = ?";

        $consulta = $this->conexao->prepare($sql);
        $consulta->execute([$idCarteira]);

        $carteira = $consulta->fetch(PDO::FETCH_ASSOC);

        // Confirma que o registro existe
        $this->assertNotFalse($carteira);

        // Confirma o ID
        $this->assertEquals(
            $idCarteira,
            $carteira['id_carteira']
        );

        // Confirma o usuário
        $this->assertEquals(
            $idUsuario,
            $carteira['id_usuario']
        );

        // Confirma o nome
        $this->assertEquals(
            $this->nomeCarteiraTeste,
            $carteira['nome_carteira']
        );

        // Confirma o tipo
        $this->assertEquals(
            $tipoCarteira,
            $carteira['tipo_carteira']
        );

        // Confirma o saldo
        $this->assertEquals(
            $saldoLivre,
            $carteira['saldo_livre_carteira']
        );
    }
}
?>