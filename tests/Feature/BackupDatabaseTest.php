<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backups-'.uniqid());
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function crear(string $nombre, int $edadDias = 0): string
    {
        $ruta = $this->dir.'/'.$nombre;
        file_put_contents($ruta, 'PGDMP'.str_repeat('x', 2048));
        touch($ruta, time() - $edadDias * 86400);

        return $ruta;
    }

    public function test_no_soporta_sqlite(): void
    {
        // La suite corre en SQLite: el comando debe negarse en vez de generar basura.
        $this->artisan('db:backup', ['--dir' => $this->dir])
            ->expectsOutputToContain('solo soporta PostgreSQL')
            ->assertFailed();
    }

    public function test_la_rotacion_conserva_las_mas_recientes_y_respeta_las_manuales(): void
    {
        // Copias automáticas (con marca de hora), de más antigua a más reciente.
        $vieja = $this->crear('contratacion-backup-2026-09-01-0800.dump', 6);
        $media = $this->crear('contratacion-backup-2026-09-05-0800.dump', 2);
        $nueva = $this->crear('contratacion-backup-2026-09-07-0800.dump', 0);

        // Copias que NO genera el comando: una de hito y otra con otro nombre.
        $hito = $this->crear('contratacion-backup-2026-07-10.dump', 60);
        $manual = $this->crear('contratacion-backup-antes-de-migrar.dump', 30);

        $comando = new \App\Console\Commands\BackupDatabase;
        $rotar = new \ReflectionMethod($comando, 'rotar');
        $comando->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\BufferedOutput
        ));
        $rotar->invoke($comando, $this->dir, 2);

        $this->assertFileExists($nueva, 'la más reciente debe conservarse');
        $this->assertFileExists($media, 'la segunda más reciente debe conservarse');
        $this->assertFileDoesNotExist($vieja, 'la tercera debe rotarse');

        // Lo importante: la rotación no toca lo que no ha creado ella.
        $this->assertFileExists($hito, 'una copia de hito no debe borrarse');
        $this->assertFileExists($manual, 'una copia manual no debe borrarse');
    }

    public function test_reconoce_un_volcado_truncado(): void
    {
        $bueno = $this->crear('contratacion-backup-2026-09-07-0900.dump');
        $malo = $this->dir.'/roto.dump';
        file_put_contents($malo, 'esto no es un volcado');

        $comando = new \App\Console\Commands\BackupDatabase;
        $valido = new \ReflectionMethod($comando, 'esVolcadoValido');

        $this->assertTrue($valido->invoke($comando, $bueno));
        $this->assertFalse($valido->invoke($comando, $malo), 'sin cabecera PGDMP no vale');
        $this->assertFalse($valido->invoke($comando, $this->dir.'/no-existe.dump'));
    }
}
