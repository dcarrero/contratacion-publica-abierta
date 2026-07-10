<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Guardia anti-wipe: DEBE ejecutarse ANTES de parent::setUp(), porque el trait
        // RefreshDatabase se dispara dentro de parent::setUp() (vía setUpTraits) y ahí ya
        // intentaría conectar y migrar. En ese punto la app aún no está booteada, así que
        // leemos el entorno directamente en vez de config(). Segunda capa de defensa por si
        // el entorno (p. ej. .env.docker con DB_CONNECTION=pgsql) se filtrara pese al
        // force="true" de phpunit.xml.
        $this->assertSafeTestDatabase();

        parent::setUp();

        // El CI no compila assets (no hay public/build/manifest.json), así que las vistas
        // con @vite() lanzarían ViteManifestNotFoundException. Los tests no validan el
        // bundle, sólo la lógica; withoutVite() hace que @vite() renderice vacío.
        $this->withoutVite();
    }

    private function assertSafeTestDatabase(): void
    {
        $readEnv = static function (string $key): ?string {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

            return ($value === false || $value === null || $value === '') ? null : (string) $value;
        };

        $conn = $readEnv('DB_CONNECTION') ?? 'sqlite';
        // La conexión sqlite resuelve su fichero desde DB_SQLITE_DATABASE (ver config/database.php),
        // con DB_DATABASE como respaldo; por defecto :memory:.
        $sqliteDb = $readEnv('DB_SQLITE_DATABASE') ?? $readEnv('DB_DATABASE') ?? ':memory:';

        if ($conn !== 'sqlite' || $sqliteDb !== ':memory:') {
            fwrite(STDERR, "\n\033[41m ABORT \033[0m Los tests deben usar sqlite :memory:, "
                ."pero el entorno resuelve la conexión a '{$conn}' / '{$sqliteDb}'. "
                ."Se aborta para no arrasar una base de datos real.\n");
            exit(1);
        }
    }
}
