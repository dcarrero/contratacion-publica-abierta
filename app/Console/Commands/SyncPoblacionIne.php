<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ComunidadAutonoma;
use App\Models\Provincia;
use Illuminate\Console\Command;

class SyncPoblacionIne extends Command
{
    protected $signature = 'ine:sync-poblacion';

    protected $description = 'Actualiza datos de población INE (Censo 2024, 1 enero 2024) en CCAA y provincias';

    public function handle(): int
    {
        $this->info('Actualizando población INE 2024...');

        // Fuente: INE, Cifras oficiales de población, RD 1210/2024 (1 enero 2024)
        // https://www.ine.es/dynt3/inebase/es/index.html?padre=517
        $ccaaData = [
            'ES11' => 2_695_645,  // Galicia
            'ES12' => 1_011_792,  // Asturias
            'ES13' => 585_493,    // Cantabria
            'ES21' => 2_213_993,  // País Vasco
            'ES22' => 664_117,    // Navarra
            'ES23' => 315_223,    // La Rioja
            'ES24' => 1_340_498,  // Aragón
            'ES30' => 6_928_679,  // Madrid
            'ES41' => 2_383_139,  // Castilla y León
            'ES42' => 2_084_086,  // Castilla-La Mancha
            'ES43' => 1_054_828,  // Extremadura
            'ES51' => 7_901_963,  // Cataluña
            'ES52' => 5_216_195,  // Comunitat Valenciana
            'ES53' => 1_210_750,  // Illes Balears
            'ES61' => 8_542_926,  // Andalucía
            'ES62' => 1_556_891,  // Región de Murcia
            'ES63' => 82_376,     // Ceuta
            'ES64' => 85_618,     // Melilla
            'ES70' => 2_245_741,  // Canarias
        ];

        $updated = 0;
        foreach ($ccaaData as $nuts => $poblacion) {
            $rows = ComunidadAutonoma::where('nuts', $nuts)->update(['poblacion' => $poblacion]);
            if ($rows > 0) {
                $updated++;
            }
        }
        $this->info("  CCAA actualizadas: {$updated}/19");

        // Provincias: código INE → población (Censo 2024)
        $provData = [
            '01' => 337_397,   // Álava
            '02' => 388_270,   // Albacete
            '03' => 1_956_651, // Alicante
            '04' => 746_483,   // Almería
            '05' => 157_664,   // Ávila
            '06' => 667_561,   // Badajoz
            '07' => 1_210_750, // Illes Balears
            '08' => 5_781_190, // Barcelona
            '09' => 352_123,   // Burgos
            '10' => 387_267,   // Cáceres
            '11' => 1_239_889, // Cádiz
            '12' => 578_164,   // Castellón
            '13' => 502_578,   // Ciudad Real
            '14' => 775_944,   // Córdoba
            '15' => 1_120_156, // A Coruña
            '16' => 193_588,   // Cuenca
            '17' => 781_788,   // Girona
            '18' => 919_168,   // Granada
            '19' => 253_686,   // Guadalajara
            '20' => 731_298,   // Gipuzkoa
            '21' => 519_932,   // Huelva
            '22' => 228_409,   // Huesca
            '23' => 627_428,   // Jaén
            '24' => 442_495,   // León
            '25' => 446_780,   // Lleida
            '26' => 315_223,   // La Rioja
            '27' => 325_498,   // Lugo
            '28' => 6_928_679, // Madrid
            '29' => 1_713_715, // Málaga
            '30' => 1_556_891, // Murcia
            '31' => 664_117,   // Navarra
            '32' => 303_188,   // Ourense
            '33' => 1_011_792, // Asturias
            '34' => 159_521,   // Palencia
            '35' => 1_145_230, // Las Palmas
            '36' => 946_803,   // Pontevedra
            '37' => 327_428,   // Salamanca
            '38' => 1_100_511, // Santa Cruz de Tenerife
            '39' => 585_493,   // Cantabria
            '40' => 151_640,   // Segovia
            '41' => 1_943_668, // Sevilla
            '42' => 88_003,    // Soria
            '43' => 892_205,   // Tarragona
            '44' => 133_325,   // Teruel
            '45' => 745_964,   // Toledo
            '46' => 2_612_049, // Valencia
            '47' => 517_641,   // Valladolid
            '48' => 1_145_298, // Bizkaia
            '49' => 168_711,   // Zamora
            '50' => 978_764,   // Zaragoza
            '51' => 82_376,    // Ceuta
            '52' => 85_618,    // Melilla
        ];

        $updatedProv = 0;
        foreach ($provData as $codigoIne => $poblacion) {
            $rows = Provincia::where('codigo_ine', $codigoIne)->update(['poblacion' => $poblacion]);
            if ($rows > 0) {
                $updatedProv++;
            }
        }
        $this->info("  Provincias actualizadas: {$updatedProv}/52");

        $this->info('Población INE 2024 actualizada.');

        return self::SUCCESS;
    }
}
