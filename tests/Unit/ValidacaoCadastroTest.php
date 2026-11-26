<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Services/ValidacaoCadastroService.php';

final class ValidacaoCadastroTest extends TestCase
{
    public function testTelefoneOpcionalEGuardadoSoComDigitos(): void
    {
        $this->assertSame('', telefoneSomenteDigitos(''));
        $this->assertSame('11912345678', telefoneSomenteDigitos('(11) 91234-5678'));
        $this->assertSame('11912345678', telefoneSomenteDigitos('+55 11 91234-5678'));
        $this->assertSame('1131234567', telefoneSomenteDigitos('(11) 3123-4567'));
    }

    public function testTelefoneInvalido(): void
    {
        $this->assertNull(telefoneSomenteDigitos('1234'));
        $this->assertNull(telefoneSomenteDigitos('0912345678'));
        $this->assertNull(telefoneSomenteDigitos('119123456789'));
    }

    public function testTelefoneFormatadoCabeNaTelaENaoNoBanco(): void
    {
        $this->assertSame('(11) 91234-5678', formatarTelefone('11912345678'));
        $this->assertSame('(11) 3123-4567', formatarTelefone('1131234567'));
        $this->assertNull(formatarTelefone(null));
    }

    public function testCpf(): void
    {
        $this->assertTrue(cpfValido('529.982.247-25'));
        $this->assertTrue(cpfValido('52998224725'));
        $this->assertFalse(cpfValido('529.982.247-26'));
        $this->assertFalse(cpfValido('111.111.111-11'));
        $this->assertFalse(cpfValido('123'));
        $this->assertSame('***.982.247-**', mascararCpf('52998224725'));
        $this->assertNull(mascararCpf(null));
    }
}
