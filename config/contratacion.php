<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sitio público (dominio propio del proyecto)
    |--------------------------------------------------------------------------
    | URL pública final del proyecto. De momento contratacionabierta.com (nuestro).
    | Se usa en la marca de los informes PDF, emails, etc. Cambiar aquí (o por env)
    | si se fija otro dominio definitivo.
    */
    'sitio' => [
        'nombre' => env('SITIO_NOMBRE', 'Contratación Abierta'),
        'dominio' => env('SITIO_DOMINIO', 'contratacionabierta.com'),
        'url' => env('SITIO_URL', 'https://contratacionabierta.com'),
    ],



    /*
    |--------------------------------------------------------------------------
    | Feeds PLACSP (Plataforma de Contratación del Sector Público)
    |--------------------------------------------------------------------------
    */
    'placsp' => [
        'licitaciones_feed' => 'https://contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3.atom',
        'menores_feed' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1143/contratosMenoresPerfilesContratantes.atom',
        'organos_feed' => 'https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1044/PlatasPerfilesContratantes.atom',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipos de contrato (CODICE)
    |--------------------------------------------------------------------------
    */
    'tipos_contrato' => [
        1 => 'Suministros',
        2 => 'Servicios',
        3 => 'Obras',
        21 => 'Gestión de Servicios Públicos',
        31 => 'Concesión de Obras Públicas',
        40 => 'Colaboración público-privada',
        7 => 'Patrimonial',
        8 => 'Administrativo especial',
        999 => 'Otros',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipos de procedimiento (CODICE)
    |--------------------------------------------------------------------------
    */
    'procedimientos' => [
        1 => 'Abierto',
        2 => 'Restringido',
        3 => 'Negociado sin publicidad',
        4 => 'Negociado con publicidad',
        5 => 'Diálogo competitivo',
        6 => 'Contrato menor',
        7 => 'Basado en Acuerdo Marco',
        8 => 'Sistema dinámico de adquisición',
        9 => 'Asociación para la innovación',
        100 => 'Otros',
    ],

    /*
    |--------------------------------------------------------------------------
    | Estados del contrato — mapeo canónico
    | Clave = etiqueta visible, Valor = array de variantes en BD
    |--------------------------------------------------------------------------
    */
    'estados' => [
        'Resuelta' => ['RES', 'Resuelta', 'Resuelto'],
        'Adjudicada' => ['ADJ', 'Adjudicada', 'Adjudicado'],
        'Activo' => ['ACTIVO'],
        'Publicada' => ['PUB', 'Publicada'],
        'En evaluación' => ['EV', 'En evaluación', 'Evaluación'],
        'Formalizada' => ['FI', 'Formalizado', 'Formalizada'],
        'Plazo cerrado' => ['CERR', 'Plazo cerrado'],
        'Preanuncio' => ['PRE', 'Preanuncio'],
        'Anulada' => ['ANUL', 'AN', 'Anulada', 'Anulado'],
        'Modificada' => ['MO', 'Modificada'],
        'Desierta' => ['DS', 'Desierto', 'Desierta'],
        'Desistimiento/Renuncia' => ['Desistimiento / Renuncia'],
        'Histórico' => ['Histórico'],
        'Abierta' => ['Abierto', 'Abierta', 'Anuncios abiertos en licitación / En plazo'],
        'Reasignada' => ['REA'],
        'Sin resolver' => ['SR'],
        'Borrador' => ['BORR', 'CREA', 'Borrador'],
        'Suspendida' => ['Suspendido', 'Suspendida'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Códigos de resultado de adjudicación (TenderResult)
    |--------------------------------------------------------------------------
    */
    'resultados' => [
        1 => 'Adjudicado',
        2 => 'Desierto',
        3 => 'Desistido',
        4 => 'Renuncia',
        5 => 'Excluido',
        8 => 'Provisional',
    ],

    /*
    |--------------------------------------------------------------------------
    | Códigos de urgencia
    |--------------------------------------------------------------------------
    */
    'urgencias' => [
        1 => 'Normal',
        2 => 'Urgente',
        3 => 'Emergencia',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipos de identificador de adjudicatario
    |--------------------------------------------------------------------------
    */
    'tipos_identificador' => [
        'NIF' => 'NIF/CIF español',
        'NIE' => 'NIE (extranjeros residentes)',
        'VAT' => 'Número IVA intracomunitario',
        'PASSPORT' => 'Pasaporte',
        'OTHER' => 'Otro identificador',
    ],

    /*
    |--------------------------------------------------------------------------
    | Alertas y suscripciones
    |--------------------------------------------------------------------------
    */
    'alertas' => [
        'frecuencias' => ['diaria', 'semanal'],
        'token_expiry_hours' => 48,
        'max_por_email' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Detección de anomalías — umbrales
    |--------------------------------------------------------------------------
    */
    'anomalias' => [
        'fraccionamiento' => [
            'dias' => 90,
            'min_contratos' => 3,
            'umbral_servicios' => 15000,
            'umbral_obras' => 40000,
            'ratio_umbral' => 0.80,
        ],
        'concentracion' => [
            'umbral_porcentaje' => 80,
            'min_contratos' => 5,
        ],
        'pico_temporal' => [
            'multiplicador' => 3,
            'min_historico_meses' => 12,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Informes y exportación
    |--------------------------------------------------------------------------
    */
    'informes' => [
        'max_csv_rows' => 500000,
        'top_adjudicatarios' => 20,
        'top_organismos' => 20,
        'top_cpv' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grafo de relaciones
    |--------------------------------------------------------------------------
    */
    'grafo' => [
        'top_relaciones' => 150,
        'top_nodos' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel de administración
    |--------------------------------------------------------------------------
    */
    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@contratacionabierta.com'),
        'default_password' => env('ADMIN_PASSWORD', 'change-me-now'),
        'allowed_ips' => array_filter(explode(',', env('ADMIN_ALLOWED_IPS', '127.0.0.1,::1'))),
        'commands' => [
            'stats:recalculate',
            'anomalias:detectar',
            'nif:normalize --dry-run',
            'cache:clear',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Importación
    |--------------------------------------------------------------------------
    */
    'import' => [
        'chunk_size' => 500,
        'timeout_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sincronización PLACSP
    |--------------------------------------------------------------------------
    */
    'placsp_sync' => [
        'max_pages' => 500,
        'timeout_seconds' => 30,
        'retry_attempts' => 3,
        'retry_delay_ms' => 1000,
        'delay_between_pages_ms' => 1000,
        'max_consecutive_errors' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fuentes regionales
    |--------------------------------------------------------------------------
    */
    'regional' => [
        'catalunya' => [
            'api_url' => 'https://analisi.transparenciacatalunya.cat/resource/ybgg-dgi6.json',
            'page_size' => 10000,
            'default_nuts' => 'ES51',
        ],
        'andalucia' => [
            'base_url' => 'https://www.juntadeandalucia.es/datosabiertos/portal/',
            'default_nuts' => 'ES61',
        ],
        'castilla_y_leon' => [
            'base_url' => 'https://datosabiertos.jcyl.es/',
            'default_nuts' => 'ES41',
        ],
        'pais_vasco' => [
            'json_url' => 'https://opendata.euskadi.eus/contenidos/ds_contrataciones/contrataciones_admin_{YEAR}/opendata/contratos.json',
            'default_nuts' => 'ES21',
        ],
        'madrid' => [
            'feed_url' => 'https://contratos-publicos.comunidad.madrid/feed/licitaciones2',
            'default_nuts' => 'ES30',
        ],
        'valencia' => [
            'default_nuts' => 'ES52',
        ],
        'canarias' => [
            'default_nuts' => 'ES70',
        ],
        'aragon' => [
            'default_nuts' => 'ES24',
        ],
        'asturias' => [
            'base_url' => 'https://descargas.asturias.es/asturias/opendata/SectorPublico/contratacion/',
            'default_nuts' => 'ES12',
        ],
        'murcia' => [
            'base_url_mayor' => 'https://datosabiertos.carm.es/odata/transparencia/contratosOD',
            'base_url_menor' => 'https://datosabiertos.carm.es/odata/Hacienda/CONTRA_ContratosMenores_',
            'default_nuts' => 'ES62',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Traducciones catalán → castellano
    |--------------------------------------------------------------------------
    */
    'traducciones_catalan' => [
        // Tipos de contrato
        'Serveis' => 'Servicios',
        'Obres' => 'Obras',
        'Subministraments' => 'Suministros',
        'Gestió de serveis públics' => 'Gestión de Servicios Públicos',
        'Concessió d\'obres públiques' => 'Concesión de Obras Públicas',
        'Col·laboració públicoprivada' => 'Colaboración público-privada',
        'Patrimonial' => 'Patrimonial',
        'Administratiu especial' => 'Administrativo especial',
        'Privat' => 'Privado',
        'Mixt' => 'Mixto',
        // Procedimientos
        'Contracte menor' => 'Contrato menor',
        'Obert' => 'Abierto',
        'Restringit' => 'Restringido',
        'Negociat sense publicitat' => 'Negociado sin publicidad',
        'Negociat amb publicitat' => 'Negociado con publicidad',
        'Diàleg competitiu' => 'Diálogo competitivo',
        'Basat en Acord Marc' => 'Basado en Acuerdo Marco',
    ],

    /*
    |--------------------------------------------------------------------------
    | Divisiones CPV (2 dígitos) — vocabulario común de contratación pública
    |--------------------------------------------------------------------------
    */
    'cpv_divisiones' => [
        '03' => 'Productos agrícolas y ganaderos',
        '09' => 'Productos petrolíferos y combustibles',
        '14' => 'Productos de minería y canteras',
        '15' => 'Productos alimenticios y bebidas',
        '16' => 'Maquinaria agrícola',
        '18' => 'Prendas de vestir y accesorios',
        '22' => 'Material impreso y productos relacionados',
        '24' => 'Productos químicos',
        '30' => 'Equipos informáticos y suministros',
        '31' => 'Equipos y aparatos eléctricos',
        '32' => 'Equipos de telecomunicaciones',
        '33' => 'Equipos médicos y farmacéuticos',
        '34' => 'Equipos de transporte',
        '35' => 'Equipos de seguridad y defensa',
        '37' => 'Instrumentos musicales y deportivos',
        '38' => 'Equipos de laboratorio y científicos',
        '39' => 'Mobiliario y equipamiento',
        '42' => 'Maquinaria industrial',
        '43' => 'Maquinaria de minería y construcción',
        '44' => 'Materiales de construcción',
        '45' => 'Trabajos de construcción',
        '48' => 'Paquetes de software',
        '50' => 'Servicios de reparación y mantenimiento',
        '51' => 'Servicios de instalación',
        '55' => 'Servicios de hostelería y restauración',
        '60' => 'Servicios de transporte terrestre',
        '63' => 'Servicios de agencias de viajes',
        '64' => 'Servicios postales y telecomunicaciones',
        '65' => 'Servicios públicos (electricidad, gas, agua)',
        '66' => 'Servicios financieros y de seguros',
        '70' => 'Servicios inmobiliarios',
        '71' => 'Servicios de arquitectura e ingeniería',
        '72' => 'Servicios de tecnología de la información',
        '73' => 'Servicios de I+D',
        '75' => 'Servicios de administración pública',
        '76' => 'Servicios de silvicultura',
        '77' => 'Servicios agrícolas y jardinería',
        '79' => 'Servicios empresariales y de consultoría',
        '80' => 'Servicios de educación y formación',
        '85' => 'Servicios sanitarios y sociales',
        '90' => 'Servicios de alcantarillado y residuos',
        '92' => 'Servicios recreativos y culturales',
        '98' => 'Otros servicios comunitarios',
    ],
];
