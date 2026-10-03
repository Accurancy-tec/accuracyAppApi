<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../service/ativoService.php";

final class AtivoServiceTest extends TestCase
{
    private PDO $conexao;
    private string $simboloTeste;
    private int $idAtivoTeste;
    public function setUp(): void
    {
        parent::setUp();

        $this->conexao = require __DIR__ . "/../database/conexao.php";
        $this->simboloTeste = 'TEST_' . time();
    }

    public function tearDown(): void
    {
        if (isset($this->idAtivoTeste)) {
            $sql = "DELETE FROM ativo WHERE id_ativo = ?";

            $del = $this->conexao->prepare($sql);
            $del->execute([$this->idAtivoTeste]);
        }

        parent::tearDown();
    }

    public function testCriarAtivoQuandoNaoExiste(): void
    {
        $idAtivo = buscarOuCriarAtivo(
            $this->conexao,
            $this->simboloTeste,
            "Ativo de teste",
            "Ações"
        );

        $this->idAtivoTeste = $idAtivo;

        $this->assertIsNumeric($idAtivo);
        $this->assertGreaterThan(0, $idAtivo);

        $sql = "SELECT id_ativo,
                       simbolo_ativo,
                       nome_ativo,
                       categoria_ativo
                FROM ativo
                WHERE id_ativo = ?";

        $consulta = $this->conexao->prepare($sql);
        $consulta->execute([$idAtivo]);

        $ativo = $consulta->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($ativo);

        $this->assertEquals(
            $idAtivo,
            $ativo['id_ativo']
        );

        $this->assertEquals(
            $this->simboloTeste,
            $ativo['simbolo_ativo']
        );

        $this->assertEquals(
            "Ativo de teste",
            $ativo['nome_ativo']
        );

        $this->assertEquals(
            "Ações",
            $ativo['categoria_ativo']
        );
    }

    public function testRetornarAtivoQuandoJaExiste(): void
    {
        $idPrimeiro = buscarOuCriarAtivo(
            $this->conexao,
            $this->simboloTeste,
            "Ativo de teste",
            "Ações"
        );

        $this->idAtivoTeste = $idPrimeiro;

        $idSegundo = buscarOuCriarAtivo(
            $this->conexao,
            $this->simboloTeste,
            "Outro nome",
            "Cripto"
        );

        $this->assertSame(
            $idPrimeiro,
            $idSegundo
        );

        // Confirma que não criou outro registro
        $sql = "SELECT COUNT(*)
                FROM ativo
                WHERE simbolo_ativo = ?";

        $consulta = $this->conexao->prepare($sql);
        $consulta->execute([$this->simboloTeste]);

        $quantidade = $consulta->fetchColumn();

        $this->assertEquals(1, $quantidade);
    }
}
?>