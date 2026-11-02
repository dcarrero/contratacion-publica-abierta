<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeUrlTest extends TestCase
{
    public function test_for_link_solo_deja_pasar_http_y_https(): void
    {
        $this->assertSame('https://contrataciondelestado.es/x', SafeUrl::forLink('https://contrataciondelestado.es/x'));
        $this->assertSame('http://ejemplo.es/', SafeUrl::forLink(' http://ejemplo.es/ '));
        $this->assertNull(SafeUrl::forLink('javascript:alert(1)'));
        $this->assertNull(SafeUrl::forLink(' JavaScript:alert(1)'));
        $this->assertNull(SafeUrl::forLink('data:text/html,<script>alert(1)</script>'));
        $this->assertNull(SafeUrl::forLink('/ruta/relativa'));
        $this->assertNull(SafeUrl::forLink(null));
    }

    /** @return array<string, array{string, bool}> */
    public static function descargas(): array
    {
        return [
            'host exacto' => ['https://euskadi.eus/a.xml', true],
            'subdominio' => ['https://www.contratacion.euskadi.eus/a.xml', true],
            'puerto 443 explícito' => ['https://www.contratacion.euskadi.eus:443/a.xml', true],
            'http' => ['http://www.contratacion.euskadi.eus/a.xml', false],
            'otro dominio' => ['https://evil.example/a.xml', false],
            'sufijo engañoso' => ['https://noeuskadi.eus/a.xml', false],
            'dominio permitido como subdominio de otro' => ['https://euskadi.eus.evil.example/a.xml', false],
            'credenciales' => ['https://euskadi.eus@169.254.169.254/latest', false],
            'usuario en host permitido' => ['https://user:pass@www.euskadi.eus/a.xml', false],
            'IP de metadatos' => ['https://169.254.169.254/latest/meta-data', false],
            'loopback IPv6' => ['https://[::1]/', false],
            'puerto raro' => ['https://www.euskadi.eus:8443/a.xml', false],
            'file' => ['file:///etc/passwd', false],
            'basura' => ['no es una url', false],
        ];
    }

    #[DataProvider('descargas')]
    public function test_is_allowed_download(string $url, bool $esperado): void
    {
        $this->assertSame($esperado, SafeUrl::isAllowedDownload($url, ['euskadi.eus']));
    }
}
