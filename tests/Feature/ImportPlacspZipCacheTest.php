<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Revalidación de la caché de ZIPs en placsp:import-zip.
 *
 * El bug que cubren estos tests: los ZIPs de un periodo abierto (año o mes en curso) crecen a
 * diario en origen, pero el comando reutilizaba a ciegas el fichero local. Reprocesar un volcado
 * viejo reporta "0 nuevos", que se lee como "estamos al día" cuando significa lo contrario.
 *
 * Todos los tests corren sin red: la descarga vía binario curl se salta bajo tests
 * (App::runningUnitTests) para que Http::fake() intercepte todas las peticiones.
 */
class ImportPlacspZipCacheTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        // El comando escribe en storage_path('app/placsp-zips'), que en real es un directorio de
        // ~16 GB. Se redirige a un temporal para no tocarlo ni borrar nada del usuario.
        $this->storageDir = sys_get_temp_dir().'/placsp-zip-cache-'.bin2hex(random_bytes(6));
        mkdir($this->storageDir.'/app/placsp-zips', 0755, true);
        $this->app->useStoragePath($this->storageDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->storageDir);

        parent::tearDown();
    }

    public function test_cache_de_periodo_cerrado_se_reutiliza_sin_tocar_la_red(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $zip = $this->makeCachedZip('agregacion', '2019');

        $this->runImport('2019')
            ->expectsOutputToContain('Caché local válida')
            ->assertSuccessful();

        // Un periodo cerrado es inmutable: ni una petición, ni siquiera para revalidar.
        Http::assertNothingSent();
        $this->assertFileExists($zip);
    }

    public function test_cache_obsoleta_del_periodo_en_curso_se_descarta_y_se_redescarga(): void
    {
        // Remoto regenerado ayer; la copia local es de hace tres meses.
        Http::fake(['*' => Http::response('', 200, [
            'Last-Modified' => gmdate('D, d M Y H:i:s', time() - 86400).' GMT',
        ])]);

        $zip = $this->makeCachedZip('agregacion', $this->currentYear(), time() - (90 * 86400));

        $this->runImport($this->currentYear())
            ->expectsOutputToContain('Caché local obsoleta')
            ->expectsOutputToContain('Descargando:')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->hasHeader('If-Modified-Since'));
        $this->assertFileDoesNotExist($zip, 'El volcado obsoleto debe descartarse.');
    }

    public function test_cache_del_periodo_en_curso_revalidada_con_304_se_reutiliza(): void
    {
        Http::fake(['*' => Http::response('', 304)]);

        $zip = $this->makeCachedZip('agregacion', $this->currentYear(), time() - (90 * 86400));

        $this->runImport($this->currentYear())
            ->expectsOutputToContain('Caché local válida')
            ->assertSuccessful();

        // Una sola petición: el GET condicional. El 304 evita bajar los 100 MB.
        Http::assertSentCount(1);
        $this->assertFileExists($zip);
    }

    public function test_force_download_ignora_incluso_la_cache_de_un_periodo_cerrado(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $zip = $this->makeCachedZip('agregacion', '2019');

        $this->runImport('2019', ['--force-download' => true])
            ->expectsOutputToContain('--force-download')
            ->expectsOutputToContain('Descargando:')
            ->assertSuccessful();

        // Con --force-download no se revalida nada: se va directo a la descarga.
        Http::assertSent(fn ($request) => ! $request->hasHeader('If-Modified-Since'));
        $this->assertFileDoesNotExist($zip);
    }

    public function test_si_no_se_puede_revalidar_una_cache_reciente_se_conserva(): void
    {
        Http::fake(fn () => throw new ConnectionException('TLS handshake failed'));

        $zip = $this->makeCachedZip('agregacion', $this->currentYear(), time() - 3600);

        $this->runImport($this->currentYear())
            ->expectsOutputToContain('No se pudo revalidar')
            ->expectsOutputToContain('Caché local válida')
            ->assertSuccessful();

        $this->assertFileExists($zip);
    }

    public function test_si_no_se_puede_revalidar_una_cache_vieja_se_descarta(): void
    {
        Http::fake(fn () => throw new ConnectionException('TLS handshake failed'));

        $zip = $this->makeCachedZip('agregacion', $this->currentYear(), time() - (48 * 3600));

        $this->runImport($this->currentYear())
            ->expectsOutputToContain('No se pudo revalidar')
            ->expectsOutputToContain('Caché local obsoleta')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($zip);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function runImport(string $period, array $extra = []): \Illuminate\Testing\PendingCommand
    {
        /** @var \Illuminate\Testing\PendingCommand $command */
        $command = $this->artisan('placsp:import-zip', array_merge([
            'type' => 'agregacion',
            'period' => $period,
            // dry-run evita tocar BD; keep-zips conserva el ZIP para poder afirmar sobre él.
            '--dry-run' => true,
            '--keep-zips' => true,
        ], $extra));

        return $command;
    }

    private function currentYear(): string
    {
        return now()->format('Y');
    }

    /**
     * Crea un ZIP válido en la caché. El contenido es aleatorio a propósito: no comprime, así
     * el fichero supera el kilobyte por debajo del cual el comando lo considera inservible.
     */
    private function makeCachedZip(string $prefix, string $period, ?int $mtime = null): string
    {
        $path = $this->storageDir."/app/placsp-zips/{$prefix}_{$period}.zip";

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('relleno.bin', random_bytes(4096));
        $zip->close();

        if ($mtime !== null) {
            touch($path, $mtime);
        }

        return $path;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir.'/*') as $entry) {
            is_dir((string) $entry) ? $this->deleteDirectory((string) $entry) : @unlink((string) $entry);
        }

        @rmdir($dir);
    }
}
