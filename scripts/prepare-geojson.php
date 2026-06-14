<?php

declare(strict_types=1);

/**
 * Script one-time: descarga GeoJSON de Eurostat GISCO y filtra solo España.
 *
 * Genera:
 *   public/geojson/spain-ccaa.json       (NUTS Level 2 = CCAA)
 *   public/geojson/spain-provincias.json  (NUTS Level 3 = Provincias)
 *
 * Uso: php scripts/prepare-geojson.php
 */
$baseUrl = 'https://gisco-services.ec.europa.eu/distribution/v2/nuts/geojson';
$outputDir = __DIR__.'/../public/geojson';

$sources = [
    [
        'url' => "$baseUrl/NUTS_RG_20M_2024_4326_LEVL_2.geojson",
        'output' => "$outputDir/spain-ccaa.json",
        'label' => 'CCAA (NUTS Level 2)',
    ],
    [
        'url' => "$baseUrl/NUTS_RG_20M_2024_4326_LEVL_3.geojson",
        'output' => "$outputDir/spain-provincias.json",
        'label' => 'Provincias (NUTS Level 3)',
    ],
];

foreach ($sources as $source) {
    echo "Descargando {$source['label']}...\n";
    echo "  URL: {$source['url']}\n";

    $json = file_get_contents($source['url']);
    if ($json === false) {
        echo "  ERROR: No se pudo descargar\n";

        continue;
    }

    $data = json_decode($json, true);
    if (! $data || ! isset($data['features'])) {
        echo "  ERROR: GeoJSON inválido\n";

        continue;
    }

    $totalFeatures = count($data['features']);

    // Filtrar solo features de España (CNTR_CODE === 'ES')
    $data['features'] = array_values(array_filter(
        $data['features'],
        fn (array $f) => ($f['properties']['CNTR_CODE'] ?? '') === 'ES'
    ));

    $esFeatures = count($data['features']);

    // Simplificar propiedades: solo NUTS_ID y NUTS_NAME
    foreach ($data['features'] as &$feature) {
        $feature['properties'] = [
            'NUTS_ID' => $feature['properties']['NUTS_ID'],
            'NUTS_NAME' => $feature['properties']['NUTS_NAME'] ?? $feature['properties']['NAME_LATN'] ?? '',
        ];
    }
    unset($feature);

    $output = json_encode($data, JSON_UNESCAPED_UNICODE);
    file_put_contents($source['output'], $output);

    $sizeKb = round(strlen($output) / 1024, 1);
    echo "  Filtrado: $esFeatures de $totalFeatures features (España)\n";
    echo "  Guardado: {$source['output']} ($sizeKb KB)\n\n";
}

echo "Listo.\n";
