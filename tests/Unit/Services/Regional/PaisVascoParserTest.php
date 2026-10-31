<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Regional;

use App\Services\Regional\PaisVascoParser;
use Tests\TestCase;

/**
 * Parte de los XML de detalle de Euskadi llegan en ISO-8859-1. El parser extrae los campos
 * con expresiones regulares, así que debe convertir antes a UTF-8: si no, PostgreSQL
 * rechaza el UPDATE ("invalid byte sequence for encoding UTF8").
 */
class PaisVascoParserTest extends TestCase
{
    private function xml(string $encoding): string
    {
        return '<?xml version="1.0" encoding="'.$encoding.'"?>'
            .'<contractingAnnouncement>'
            .'<tenderBudgetWithoutTaxes>1.000,00</tenderBudgetWithoutTaxes>'
            .'<businessName>Construcciones Ibáñez</businessName>'
            .'<term desc="Plazo">13</term>'
            .'<termType desc="Tipo de plazo">Días</termType>'
            .'</contractingAnnouncement>';
    }

    public function test_latin1_detail_is_converted_to_utf8(): void
    {
        $latin1 = mb_convert_encoding($this->xml('ISO-8859-1'), 'ISO-8859-1', 'UTF-8');
        $this->assertFalse(mb_check_encoding($latin1, 'UTF-8'));

        $data = (new PaisVascoParser)->parseXmlDetail($latin1);

        $this->assertSame('13 Días', $data['duracion']);
        $this->assertSame('Construcciones Ibáñez', $data['nombre_adjudicatario']);
    }

    public function test_latin1_bytes_with_a_utf8_declaration_are_also_fixed(): void
    {
        $mentiroso = mb_convert_encoding($this->xml('UTF-8'), 'ISO-8859-1', 'UTF-8');

        $data = (new PaisVascoParser)->parseXmlDetail($mentiroso);

        $this->assertSame('13 Días', $data['duracion']);
    }

    public function test_utf8_detail_is_left_untouched(): void
    {
        $data = (new PaisVascoParser)->parseXmlDetail($this->xml('UTF-8'));

        $this->assertSame('13 Días', $data['duracion']);
        $this->assertSame(1000.0, $data['importe_licitacion']);
    }
}
