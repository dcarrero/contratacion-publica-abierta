<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;

/**
 * Copia de seguridad de la base de datos con `pg_dump` en formato custom.
 *
 * Escribe primero a un fichero temporal y solo lo renombra al nombre definitivo si el
 * volcado termina bien y tiene cabecera PGDMP. Un volcado truncado restaura sin errores
 * aparentes y deja la base a medias, que es la peor forma de descubrir que no había copia:
 * más vale no tener el fichero que tenerlo mal.
 *
 * Por el mismo motivo, la rotación solo se aplica DESPUÉS de una copia correcta: si el
 * volcado falla, las copias anteriores se quedan donde están.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
        {--keep=2 : Cuántas copias conservar (las más recientes)}
        {--dir= : Directorio de destino (por defecto, backups/ del proyecto)}
        {--compress=zstd:9 : Método de compresión de pg_dump (zstd:N, gzip:N o none)}';

    protected $description = 'Crea una copia de seguridad de la base de datos y rota las antiguas';

    public function handle(): int
    {
        $conexion = Config::get('database.default');

        if ($conexion !== 'pgsql') {
            $this->error("db:backup solo soporta PostgreSQL (conexión actual: {$conexion}).");

            return self::FAILURE;
        }

        $config = Config::get('database.connections.pgsql');
        $destino = $this->option('dir') ?: base_path('backups');
        $conservar = max(1, (int) $this->option('keep'));

        if (! is_dir($destino) && ! mkdir($destino, 0755, true) && ! is_dir($destino)) {
            $this->error("No se pudo crear el directorio de destino: {$destino}");

            return self::FAILURE;
        }

        $nombre = sprintf('%s/contratacion-backup-%s.dump', rtrim($destino, '/'), date('Y-m-d-Hi'));
        $temporal = $nombre.'.parcial';

        $this->info('Creando copia de seguridad...');
        $inicio = microtime(true);

        $proceso = $this->construirProceso($config, $temporal, (string) $this->option('compress'));
        $proceso->run();

        // zstd necesita PostgreSQL 16+ en cliente y servidor. Si no está disponible, se
        // repite con la compresión por defecto (zlib) en vez de quedarse sin copia: una
        // copia más grande siempre es mejor que ninguna.
        if (! $proceso->isSuccessful() && $this->esErrorDeCompresion($proceso->getErrorOutput())) {
            $this->warn('La compresión solicitada no está disponible; se usa la de serie.');
            @unlink($temporal);
            $proceso = $this->construirProceso($config, $temporal, '');
            $proceso->run();
        }

        if (! $proceso->isSuccessful()) {
            @unlink($temporal);
            $this->error('pg_dump falló: '.trim($proceso->getErrorOutput()));

            return self::FAILURE;
        }

        if (! $this->esVolcadoValido($temporal)) {
            @unlink($temporal);
            $this->error('El volcado no tiene cabecera PGDMP: se descarta por si está truncado.');

            return self::FAILURE;
        }

        rename($temporal, $nombre);

        $tam = $this->formatearTamano((int) filesize($nombre));
        $seg = round(microtime(true) - $inicio);
        $this->info("Copia creada: {$nombre} ({$tam}, {$seg}s)");

        $this->rotar($destino, $conservar);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function construirProceso(array $config, string $destino, string $compresion): Process
    {
        $argumentos = [
            'pg_dump',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--username='.$config['username'],
            '--dbname='.$config['database'],
            '--format=custom',
            '--file='.$destino,
        ];

        // zstd:9 deja el volcado un 28% más pequeño que la compresión de serie (medido:
        // 3,13 GB frente a 4,33 GB) y además comprime más rápido por CPU consumida.
        if ($compresion !== '' && $compresion !== 'none') {
            $argumentos[] = '--compress='.$compresion;
        }

        $proceso = new Process($argumentos);

        // La contraseña por entorno y no como argumento: los argumentos son visibles para
        // cualquiera que liste los procesos de la máquina.
        $proceso->setEnv(['PGPASSWORD' => (string) $config['password']]);
        $proceso->setTimeout(3600);

        return $proceso;
    }

    private function esErrorDeCompresion(string $salida): bool
    {
        $salida = mb_strtolower($salida);

        return str_contains($salida, 'compress')
            && (str_contains($salida, 'not supported')
                || str_contains($salida, 'unrecognized')
                || str_contains($salida, 'no compatible')
                || str_contains($salida, 'invalid'));
    }

    /** Un fichero de pg_dump en formato custom empieza siempre por la marca PGDMP. */
    private function esVolcadoValido(string $ruta): bool
    {
        if (! is_file($ruta) || filesize($ruta) < 1024) {
            return false;
        }

        $manejador = fopen($ruta, 'rb');

        if ($manejador === false) {
            return false;
        }

        $cabecera = fread($manejador, 5);
        fclose($manejador);

        return $cabecera === 'PGDMP';
    }

    /**
     * Conserva las N copias automáticas más recientes y borra el resto.
     *
     * Solo entran en la rotación los ficheros con marca de hora (`-AAAA-MM-DD-HHMM.dump`),
     * que son los que genera este comando. Cualquier otra copia del directorio —una manual,
     * una de antes de una migración, un hito que alguien quiso guardar— se deja intacta.
     * La primera versión rotaba por prefijo y se llevó por delante tres copias históricas.
     */
    private function rotar(string $destino, int $conservar): void
    {
        $todas = glob(rtrim($destino, '/').'/contratacion-backup-*.dump') ?: [];

        $copias = array_values(array_filter(
            $todas,
            fn (string $ruta) => preg_match('/-\d{4}-\d{2}-\d{2}-\d{4}\.dump$/', basename($ruta)) === 1
        ));

        $protegidas = count($todas) - count($copias);

        if ($protegidas > 0) {
            $this->line("  Se respetan {$protegidas} copias que no genera este comando.");
        }

        // Ordenadas de más reciente a más antigua por fecha de modificación.
        usort($copias, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        $sobrantes = array_slice($copias, $conservar);

        foreach ($sobrantes as $sobrante) {
            $this->line('  Se elimina copia antigua: '.basename($sobrante));
            @unlink($sobrante);
        }

        $this->info(sprintf(
            'Copias conservadas: %d (de %d).',
            min(count($copias), $conservar),
            count($copias)
        ));
    }

    private function formatearTamano(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 1, ',', '.').' GB';
        }

        return number_format($bytes / 1_048_576, 0, ',', '.').' MB';
    }
}
