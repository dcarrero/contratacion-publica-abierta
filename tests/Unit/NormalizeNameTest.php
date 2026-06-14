<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\NormalizeName;
use PHPUnit\Framework\TestCase;

class NormalizeNameTest extends TestCase
{
    private NormalizeName $normalize;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalize = new NormalizeName;
    }

    public function test_removes_accents(): void
    {
        $this->assertSame('AYUNTAMIENTO DE CACERES', ($this->normalize)('Ayuntamiento de Cáceres'));
    }

    public function test_converts_to_uppercase(): void
    {
        $this->assertSame('EMPRESA TEST', ($this->normalize)('empresa test'));
    }

    public function test_normalizes_sl(): void
    {
        $this->assertSame('CONSTRUCCIONES PEREZ SL', ($this->normalize)('Construcciones Pérez S.L.'));
        $this->assertSame('CONSTRUCCIONES PEREZ SL', ($this->normalize)('Construcciones Pérez S. L.'));
    }

    public function test_normalizes_sa(): void
    {
        $this->assertSame('ACME SA', ($this->normalize)('Acme S.A.'));
        $this->assertSame('ACME SA', ($this->normalize)('Acme S. A.'));
    }

    public function test_normalizes_slu(): void
    {
        $this->assertSame('EMPRESA SLU', ($this->normalize)('Empresa S.L.U.'));
    }

    public function test_normalizes_ute(): void
    {
        $this->assertSame('UTE OBRAS NORTE', ($this->normalize)('U.T.E. Obras Norte'));
    }

    public function test_normalizes_cb(): void
    {
        $this->assertSame('HERMANOS GARCIA CB', ($this->normalize)('Hermanos García C.B.'));
    }

    public function test_removes_punctuation(): void
    {
        $this->assertSame('EMPRESA TEST 123', ($this->normalize)('Empresa, Test - 123'));
    }

    public function test_collapses_multiple_spaces(): void
    {
        $this->assertSame('EMPRESA TEST', ($this->normalize)('Empresa   Test'));
    }

    public function test_trims_whitespace(): void
    {
        $this->assertSame('EMPRESA TEST', ($this->normalize)('  Empresa Test  '));
    }

    public function test_handles_complex_name(): void
    {
        $this->assertSame(
            'CONSTRUCCIONES Y REFORMAS MARTINEZ SL',
            ($this->normalize)('Construcciones y Reformas Martínez, S.L.')
        );
    }
}
