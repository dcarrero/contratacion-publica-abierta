<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Provincia;
use Illuminate\Database\Seeder;

class ProvinciasSeeder extends Seeder
{
    public function run(): void
    {
        $provincias = [
            ['codigo_ine' => '02', 'nombre' => 'Albacete'],
            ['codigo_ine' => '13', 'nombre' => 'Ciudad Real'],
            ['codigo_ine' => '16', 'nombre' => 'Cuenca'],
            ['codigo_ine' => '19', 'nombre' => 'Guadalajara'],
            ['codigo_ine' => '45', 'nombre' => 'Toledo'],
        ];

        foreach ($provincias as $provincia) {
            Provincia::firstOrCreate(
                ['codigo_ine' => $provincia['codigo_ine']],
                $provincia
            );
        }
    }
}
