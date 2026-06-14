<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FuenteDatos;
use Illuminate\Database\Seeder;

class FuenteDatosSeeder extends Seeder
{
    public function run(): void
    {
        $fuentes = [
            [
                'nombre' => 'PLACSP - Licitaciones',
                'slug' => 'placsp-licitaciones',
                'url' => 'https://contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3.atom',
                'tipo' => 'atom',
                'frecuencia' => 'diaria',
                'activo' => true,
            ],
            [
                'nombre' => 'PLACSP - Contratos Menores',
                'slug' => 'placsp-menores',
                'url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1143/contratosMenoresPerfilesContratantes.atom',
                'tipo' => 'atom',
                'frecuencia' => 'diaria',
                'activo' => true,
            ],
            [
                'nombre' => 'PLACSP - Órganos de Contratación',
                'slug' => 'placsp-organos',
                'url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1044/PlatasPerfilesContratantes.atom',
                'tipo' => 'atom',
                'frecuencia' => 'semanal',
                'activo' => true,
            ],
            [
                'nombre' => 'CLM - Contratos de Emergencia',
                'slug' => 'clm-emergencias',
                'url' => null,
                'tipo' => 'manual',
                'frecuencia' => null,
                'activo' => false,
            ],
            [
                'nombre' => 'BQuant - Bootstrap Dataset',
                'slug' => 'bquant-bootstrap',
                'url' => null,
                'tipo' => 'csv',
                'frecuencia' => 'unica',
                'activo' => true,
            ],
            [
                'nombre' => 'Datos Abiertos CLM',
                'slug' => 'datos-abiertos-clm',
                'url' => null,
                'tipo' => 'api',
                'frecuencia' => 'mensual',
                'activo' => false,
            ],
            [
                'nombre' => 'Catalunya - Contractació Pública',
                'slug' => 'cat-contractacio',
                'url' => 'https://analisi.transparenciacatalunya.cat/resource/ybgg-dgi6.json',
                'tipo' => 'api',
                'frecuencia' => 'semanal',
                'activo' => true,
            ],
            [
                'nombre' => 'Andalucía - Contratos Menores',
                'slug' => 'anda-menores',
                'url' => 'https://www.juntadeandalucia.es/datosabiertos/portal/',
                'tipo' => 'csv',
                'frecuencia' => 'trimestral',
                'activo' => true,
            ],
            [
                'nombre' => 'País Vasco - Contratación',
                'slug' => 'eusk-contratos',
                'url' => 'https://opendata.euskadi.eus/webopd00-apicontract/api/contracts',
                'tipo' => 'api',
                'frecuencia' => 'semanal',
                'activo' => true,
            ],
            [
                'nombre' => 'Castilla y León - Contratos',
                'slug' => 'cyl-contratos',
                'url' => 'https://datosabiertos.jcyl.es/',
                'tipo' => 'csv',
                'frecuencia' => 'trimestral',
                'activo' => true,
            ],
            [
                'nombre' => 'Madrid - Contratación Pública',
                'slug' => 'madrid-contratos',
                'url' => 'https://contratos-publicos.comunidad.madrid/feed/licitaciones2',
                'tipo' => 'atom',
                'frecuencia' => 'diaria',
                'activo' => true,
            ],
            [
                'nombre' => 'Comunitat Valenciana - Contratación',
                'slug' => 'val-contratos',
                'url' => 'https://dadesobertes.gva.es/',
                'tipo' => 'csv',
                'frecuencia' => 'trimestral',
                'activo' => true,
            ],
            [
                'nombre' => 'Canarias - Contratación',
                'slug' => 'can-contratos',
                'url' => 'https://datos.canarias.es/',
                'tipo' => 'csv',
                'frecuencia' => 'mensual',
                'activo' => true,
            ],
            [
                'nombre' => 'Aragón - Contratación',
                'slug' => 'ara-contratos',
                'url' => 'https://serviciosciudadano.aragon.es/',
                'tipo' => 'csv',
                'frecuencia' => 'trimestral',
                'activo' => true,
            ],
            [
                'nombre' => 'Asturias - Contratación Centralizada',
                'slug' => 'ast-contratos',
                'url' => 'https://descargas.asturias.es/asturias/opendata/SectorPublico/contratacion/',
                'tipo' => 'csv',
                'frecuencia' => 'trimestral',
                'activo' => true,
            ],
            [
                'nombre' => 'Murcia (CARM) - Contratación',
                'slug' => 'mur-contratos',
                'url' => 'https://datosabiertos.carm.es/odata/transparencia/',
                'tipo' => 'csv',
                'frecuencia' => 'anual',
                'activo' => true,
            ],
            [
                'nombre' => 'PLACSP - Plataformas Agregadas',
                'slug' => 'placsp-agregacion',
                'url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1044/',
                'tipo' => 'atom',
                'frecuencia' => 'diaria',
                'activo' => true,
            ],
            [
                'nombre' => 'PLACSP - Encargos a Medios Propios',
                'slug' => 'placsp-emp',
                'url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1383/',
                'tipo' => 'atom',
                'frecuencia' => 'diaria',
                'activo' => true,
            ],
            [
                'nombre' => 'PLACSP - Consultas Preliminares Mercado',
                'slug' => 'placsp-cpm',
                'url' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1403/',
                'tipo' => 'atom',
                'frecuencia' => 'diaria',
                'activo' => true,
            ],
        ];

        foreach ($fuentes as $fuente) {
            FuenteDatos::firstOrCreate(
                ['slug' => $fuente['slug']],
                $fuente
            );
        }
    }
}
