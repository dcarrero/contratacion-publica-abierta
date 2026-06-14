<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ComunidadAutonoma;
use App\Models\Provincia;
use Illuminate\Database\Seeder;

class GeografiaSeeder extends Seeder
{
    public function run(): void
    {
        $ccaa = [
            ['codigo_ine' => '01', 'nombre' => 'Andalucía', 'nuts' => 'ES61'],
            ['codigo_ine' => '02', 'nombre' => 'Aragón', 'nuts' => 'ES24'],
            ['codigo_ine' => '03', 'nombre' => 'Asturias', 'nuts' => 'ES12'],
            ['codigo_ine' => '04', 'nombre' => 'Illes Balears', 'nuts' => 'ES53'],
            ['codigo_ine' => '05', 'nombre' => 'Canarias', 'nuts' => 'ES70'],
            ['codigo_ine' => '06', 'nombre' => 'Cantabria', 'nuts' => 'ES13'],
            ['codigo_ine' => '07', 'nombre' => 'Castilla y León', 'nuts' => 'ES41'],
            ['codigo_ine' => '08', 'nombre' => 'Castilla-La Mancha', 'nuts' => 'ES42'],
            ['codigo_ine' => '09', 'nombre' => 'Cataluña', 'nuts' => 'ES51'],
            ['codigo_ine' => '10', 'nombre' => 'Comunitat Valenciana', 'nuts' => 'ES52'],
            ['codigo_ine' => '11', 'nombre' => 'Extremadura', 'nuts' => 'ES43'],
            ['codigo_ine' => '12', 'nombre' => 'Galicia', 'nuts' => 'ES11'],
            ['codigo_ine' => '13', 'nombre' => 'Comunidad de Madrid', 'nuts' => 'ES30'],
            ['codigo_ine' => '14', 'nombre' => 'Región de Murcia', 'nuts' => 'ES62'],
            ['codigo_ine' => '15', 'nombre' => 'Comunidad Foral de Navarra', 'nuts' => 'ES22'],
            ['codigo_ine' => '16', 'nombre' => 'País Vasco', 'nuts' => 'ES21'],
            ['codigo_ine' => '17', 'nombre' => 'La Rioja', 'nuts' => 'ES23'],
            ['codigo_ine' => '18', 'nombre' => 'Ceuta', 'nuts' => 'ES63'],
            ['codigo_ine' => '19', 'nombre' => 'Melilla', 'nuts' => 'ES64'],
        ];

        $ccaaModels = [];
        foreach ($ccaa as $ca) {
            $ccaaModels[$ca['codigo_ine']] = ComunidadAutonoma::firstOrCreate(
                ['codigo_ine' => $ca['codigo_ine']],
                $ca
            );
        }

        $provincias = [
            // Andalucía (ES61)
            ['codigo_ine' => '04', 'nombre' => 'Almería', 'nuts' => 'ES611', 'ccaa' => '01'],
            ['codigo_ine' => '11', 'nombre' => 'Cádiz', 'nuts' => 'ES612', 'ccaa' => '01'],
            ['codigo_ine' => '14', 'nombre' => 'Córdoba', 'nuts' => 'ES613', 'ccaa' => '01'],
            ['codigo_ine' => '18', 'nombre' => 'Granada', 'nuts' => 'ES614', 'ccaa' => '01'],
            ['codigo_ine' => '21', 'nombre' => 'Huelva', 'nuts' => 'ES615', 'ccaa' => '01'],
            ['codigo_ine' => '23', 'nombre' => 'Jaén', 'nuts' => 'ES616', 'ccaa' => '01'],
            ['codigo_ine' => '29', 'nombre' => 'Málaga', 'nuts' => 'ES617', 'ccaa' => '01'],
            ['codigo_ine' => '41', 'nombre' => 'Sevilla', 'nuts' => 'ES618', 'ccaa' => '01'],
            // Aragón (ES24)
            ['codigo_ine' => '22', 'nombre' => 'Huesca', 'nuts' => 'ES241', 'ccaa' => '02'],
            ['codigo_ine' => '44', 'nombre' => 'Teruel', 'nuts' => 'ES242', 'ccaa' => '02'],
            ['codigo_ine' => '50', 'nombre' => 'Zaragoza', 'nuts' => 'ES243', 'ccaa' => '02'],
            // Asturias (ES12)
            ['codigo_ine' => '33', 'nombre' => 'Asturias', 'nuts' => 'ES120', 'ccaa' => '03'],
            // Illes Balears (ES53)
            ['codigo_ine' => '07', 'nombre' => 'Illes Balears', 'nuts' => 'ES531', 'ccaa' => '04'],
            // Canarias (ES70)
            ['codigo_ine' => '35', 'nombre' => 'Las Palmas', 'nuts' => 'ES701', 'ccaa' => '05'],
            ['codigo_ine' => '38', 'nombre' => 'Santa Cruz de Tenerife', 'nuts' => 'ES702', 'ccaa' => '05'],
            // Cantabria (ES13)
            ['codigo_ine' => '39', 'nombre' => 'Cantabria', 'nuts' => 'ES130', 'ccaa' => '06'],
            // Castilla y León (ES41)
            ['codigo_ine' => '05', 'nombre' => 'Ávila', 'nuts' => 'ES411', 'ccaa' => '07'],
            ['codigo_ine' => '09', 'nombre' => 'Burgos', 'nuts' => 'ES412', 'ccaa' => '07'],
            ['codigo_ine' => '24', 'nombre' => 'León', 'nuts' => 'ES413', 'ccaa' => '07'],
            ['codigo_ine' => '34', 'nombre' => 'Palencia', 'nuts' => 'ES414', 'ccaa' => '07'],
            ['codigo_ine' => '37', 'nombre' => 'Salamanca', 'nuts' => 'ES415', 'ccaa' => '07'],
            ['codigo_ine' => '40', 'nombre' => 'Segovia', 'nuts' => 'ES416', 'ccaa' => '07'],
            ['codigo_ine' => '42', 'nombre' => 'Soria', 'nuts' => 'ES417', 'ccaa' => '07'],
            ['codigo_ine' => '47', 'nombre' => 'Valladolid', 'nuts' => 'ES418', 'ccaa' => '07'],
            ['codigo_ine' => '49', 'nombre' => 'Zamora', 'nuts' => 'ES419', 'ccaa' => '07'],
            // Castilla-La Mancha (ES42)
            ['codigo_ine' => '02', 'nombre' => 'Albacete', 'nuts' => 'ES421', 'ccaa' => '08'],
            ['codigo_ine' => '13', 'nombre' => 'Ciudad Real', 'nuts' => 'ES422', 'ccaa' => '08'],
            ['codigo_ine' => '16', 'nombre' => 'Cuenca', 'nuts' => 'ES423', 'ccaa' => '08'],
            ['codigo_ine' => '19', 'nombre' => 'Guadalajara', 'nuts' => 'ES424', 'ccaa' => '08'],
            ['codigo_ine' => '45', 'nombre' => 'Toledo', 'nuts' => 'ES425', 'ccaa' => '08'],
            // Cataluña (ES51)
            ['codigo_ine' => '08', 'nombre' => 'Barcelona', 'nuts' => 'ES511', 'ccaa' => '09'],
            ['codigo_ine' => '17', 'nombre' => 'Girona', 'nuts' => 'ES512', 'ccaa' => '09'],
            ['codigo_ine' => '25', 'nombre' => 'Lleida', 'nuts' => 'ES513', 'ccaa' => '09'],
            ['codigo_ine' => '43', 'nombre' => 'Tarragona', 'nuts' => 'ES514', 'ccaa' => '09'],
            // Comunitat Valenciana (ES52)
            ['codigo_ine' => '03', 'nombre' => 'Alicante/Alacant', 'nuts' => 'ES521', 'ccaa' => '10'],
            ['codigo_ine' => '12', 'nombre' => 'Castellón/Castelló', 'nuts' => 'ES522', 'ccaa' => '10'],
            ['codigo_ine' => '46', 'nombre' => 'Valencia/València', 'nuts' => 'ES523', 'ccaa' => '10'],
            // Extremadura (ES43)
            ['codigo_ine' => '06', 'nombre' => 'Badajoz', 'nuts' => 'ES431', 'ccaa' => '11'],
            ['codigo_ine' => '10', 'nombre' => 'Cáceres', 'nuts' => 'ES432', 'ccaa' => '11'],
            // Galicia (ES11)
            ['codigo_ine' => '15', 'nombre' => 'A Coruña', 'nuts' => 'ES111', 'ccaa' => '12'],
            ['codigo_ine' => '27', 'nombre' => 'Lugo', 'nuts' => 'ES112', 'ccaa' => '12'],
            ['codigo_ine' => '32', 'nombre' => 'Ourense', 'nuts' => 'ES113', 'ccaa' => '12'],
            ['codigo_ine' => '36', 'nombre' => 'Pontevedra', 'nuts' => 'ES114', 'ccaa' => '12'],
            // Comunidad de Madrid (ES30)
            ['codigo_ine' => '28', 'nombre' => 'Madrid', 'nuts' => 'ES300', 'ccaa' => '13'],
            // Región de Murcia (ES62)
            ['codigo_ine' => '30', 'nombre' => 'Murcia', 'nuts' => 'ES620', 'ccaa' => '14'],
            // Comunidad Foral de Navarra (ES22)
            ['codigo_ine' => '31', 'nombre' => 'Navarra', 'nuts' => 'ES220', 'ccaa' => '15'],
            // País Vasco (ES21)
            ['codigo_ine' => '01', 'nombre' => 'Álava/Araba', 'nuts' => 'ES211', 'ccaa' => '16'],
            ['codigo_ine' => '20', 'nombre' => 'Gipuzkoa', 'nuts' => 'ES212', 'ccaa' => '16'],
            ['codigo_ine' => '48', 'nombre' => 'Bizkaia', 'nuts' => 'ES213', 'ccaa' => '16'],
            // La Rioja (ES23)
            ['codigo_ine' => '26', 'nombre' => 'La Rioja', 'nuts' => 'ES230', 'ccaa' => '17'],
            // Ceuta (ES63)
            ['codigo_ine' => '51', 'nombre' => 'Ceuta', 'nuts' => 'ES630', 'ccaa' => '18'],
            // Melilla (ES64)
            ['codigo_ine' => '52', 'nombre' => 'Melilla', 'nuts' => 'ES640', 'ccaa' => '19'],
        ];

        foreach ($provincias as $prov) {
            $ccaaId = $ccaaModels[$prov['ccaa']]->id;

            Provincia::updateOrCreate(
                ['codigo_ine' => $prov['codigo_ine']],
                [
                    'nombre' => $prov['nombre'],
                    'nuts' => $prov['nuts'],
                    'comunidad_autonoma_id' => $ccaaId,
                ]
            );
        }
    }
}
