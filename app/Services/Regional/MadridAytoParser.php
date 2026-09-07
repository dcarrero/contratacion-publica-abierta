<?php

declare(strict_types=1);

namespace App\Services\Regional;

/**
 * Parser para los CSV de CONTRATOS MENORES del Ayuntamiento de Madrid.
 *
 * Fuente: portal de datos abiertos municipal (CKAN 2.9.11), dataset
 * `300253-0-contratos-actividad-menores`, licencia CC BY 4.0, periodicidad
 * mensual declarada (desfase real observado ~6 semanas).
 *
 * POR QUÉ ESTA FUENTE
 * -------------------
 * Las LICITACIONES del Ayuntamiento sí llegan por PLACSP (sindicación 643) y no
 * se tocan aquí. Sus CONTRATOS MENORES, en cambio, no están en ninguna
 * sindicación de PLACSP: la 1143 sólo cubre organismos con perfil directo. De
 * los ~71.500 menores publicados por el Ayuntamiento, PLACSP sólo expone unos
 * 3.400. Este parser cubre ese hueco.
 *
 * DERIVA DE ESQUEMA (medida sobre los 13 CSV del dataset, 2015-2026)
 * ------------------------------------------------------------------
 * El dataset cambió de estructura cinco veces. En vez de mapear por franja
 * temporal (frágil: el año del recurso CKAN no coincide con el año de los
 * contratos), se resuelve cada campo por ALIAS DE CABECERA normalizada. Así un
 * fichero nuevo con otro nombre de columna sólo necesita un alias más.
 *
 *   2015        (9 col):  Centro; Descripción; Título del expediente; Nº exped. Adm.;
 *                         Importe; Fe.contab.; NIF; Tercero; T. expediente
 *   2016-2017  (11 col):  Ce.gestor; Descripción; Título del expediente; Nº expediente;
 *                         NIF; Tercero|Contratista; Importe; Fech/ apro;
 *                         Tipo de expediente; Fe/contab/; Documento
 *   2018-2020  (12 col):  NºRECON; NUMERO EXPEDIENTE; SECCION; ORG.CONTRATACION;
 *                         OBJETO DEL CONTRATO; TIPO DE CONTRATO; N.I.F; CONTRATISTA;
 *                         IMPORTE; FECHA APROBACION; PLAZO; FCH.COMUNIC.REG
 *   2021 (ene-feb, 12 col): CONTRATO; EXPEDIENTE; SECCIÓN; ORG_CONTRATACIÓN; OBJETO;
 *                         TIPO_CONTRATO; CIF; RAZÓN_SOCIAL; IMPORTE; F_APROBACIÓN;
 *                         PLAZO; F_INSCRIPCION
 *   2021 (mar-)-2024 (17 col): N. DE REGISTRO DE CONTRATO; N. DE EXPEDIENTE;
 *                         CENTRO - SECCION; ORGANO DE CONTRATACION; ...; PYME;
 *                         IMPORTE ADJUDICACION IVA INC.; FECHA DE ADJUDICACION;
 *                         PLAZO; FECHA DE INSCRIPCION
 *   2025-2026  (19 col):  igual + ORGANISMO_CONTRATANTE; ORGANISMO_PROMOTOR
 *
 * DECISIONES DE MAPEO (ver también SyncMadridAyto)
 * ------------------------------------------------
 * - placsp_id: prefijo `AYTMAD-` sobre el nº de registro de contrato. El prefijo
 *   evita colisiones con los ids reales de PLACSP. Los ficheros de 2015-2017 no
 *   traen nº de registro: allí se usa el nº de expediente. Ambas claves tienen
 *   duplicados en origen (~2% en 2015-2017, ~0,8% en 2018), así que el comando
 *   añade un sufijo de hash de contenido a las repeticiones (ver makePlacspId()).
 * - CPV, NUTS y URL por contrato NO EXISTEN en el CSV. El NUTS se imputa como
 *   ES300 (municipio de Madrid, NUTS3 = Madrid) y se documenta; el CPV se deja
 *   nulo antes que inventarlo; la trazabilidad se resuelve apuntando url_placsp
 *   al dataset de origen, que es la única URL real que existe.
 * - Órgano: el CSV sólo trae texto (46 valores distintos: distritos, áreas de
 *   gobierno, organismos autónomos y sociedades municipales), sin NIF ni DIR3.
 *   Se imputa el NIF del Ayuntamiento (P2807900B, DIR3 L01280796) en vez de
 *   fabricar 46 organismos sintéticos sin NIF, que violarían el principio "NIF
 *   como verdad" del proyecto y ensuciarían el ranking de organismos. El texto
 *   del centro se conserva en `lugar_ejecucion` (único campo libre disponible en
 *   `contratos`, y en la mayoría de casos el centro ES un distrito de Madrid).
 *   Contrapartida asumida y documentada: los menores de las sociedades
 *   municipales (Madrid Destino, EMT, MercaMadrid, Club de Campo) quedan
 *   atribuidos al Ayuntamiento, no a su NIF propio, porque el dataset no publica
 *   esos NIF y adivinarlos sería peor que agregarlos al ente matriz.
 * - Importes: todas las columnas de importe del dataset son CON IVA (las
 *   modernas lo dicen: "IVA INC."). Se guarda el valor en *_con_iva y se estima
 *   el importe sin IVA dividiendo entre 1,21, igual que hace CastillaYLeonParser.
 * - fecha_publicacion = fecha de inscripción en el registro de contratos (es la
 *   fecha que existe en TODAS las variantes del fichero, y las estadísticas del
 *   proyecto agregan por fecha_publicacion). fecha_adjudicacion = fecha de
 *   adjudicación/aprobación cuando el fichero la trae.
 */
class MadridAytoParser
{
    /**
     * NIF del Ayuntamiento de Madrid (entidad local, DIR3 L01280796).
     */
    public const NIF_AYUNTAMIENTO = 'P2807900B';

    public const DIR3_AYUNTAMIENTO = 'L01280796';

    public const NOMBRE_AYUNTAMIENTO = 'Ayuntamiento de Madrid';

    /**
     * NUTS3 del municipio de Madrid. Imputado: el CSV no publica código de lugar.
     */
    public const NUTS_MADRID = 'ES300';

    /**
     * Tipo de IVA general usado para estimar el importe sin IVA.
     */
    private const IVA_GENERAL = 1.21;

    /**
     * Alias de cabecera (normalizada) → campo canónico.
     *
     * La normalización elimina acentos, signos y espacios (ver normalizeHeader()),
     * de modo que 'ORG.CONTRATACION' y 'ORG_CONTRATACIÓN' colapsan en la misma clave.
     *
     * @var array<string, string>
     */
    private const HEADER_ALIASES = [
        // Identificadores
        'NDEREGISTRODECONTRATO' => 'registro',
        'NRECON' => 'registro',
        'CONTRATO' => 'registro',
        'NDEEXPEDIENTE' => 'expediente',
        'NUMEROEXPEDIENTE' => 'expediente',
        'NEXPEDIENTE' => 'expediente',
        'NEXPEDADM' => 'expediente',
        'EXPEDIENTE' => 'expediente',

        // Unidad contratante (sólo texto)
        'CENTROSECCION' => 'centro',
        'SECCION' => 'centro',
        'DESCRIPCION' => 'centro',
        'ORGANISMOCONTRATANTE' => 'organismo_contratante',
        'ORGANODECONTRATACION' => 'organo',
        'ORGCONTRATACION' => 'organo',

        // Objeto y tipo
        'OBJETODELCONTRATO' => 'objeto',
        'OBJETO' => 'objeto',
        'TITULODELEXPEDIENTE' => 'objeto',
        'TIPODECONTRATO' => 'tipo',
        'TIPOCONTRATO' => 'tipo',
        'TIPODEEXPEDIENTE' => 'tipo',
        'TEXPEDIENTE' => 'tipo',

        // Adjudicatario
        'NIFADJUDICATARIO' => 'nif',
        'NIF' => 'nif',
        'CIF' => 'nif',
        'NIF2' => 'nif',
        'RAZONSOCIALADJUDICATARIO' => 'adjudicatario',
        'RAZONSOCIAL' => 'adjudicatario',
        'CONTRATISTA' => 'adjudicatario',
        'TERCERO' => 'adjudicatario',
        'PYME' => 'pyme',

        // Importes (siempre con IVA en este dataset)
        'IMPORTEADJUDICACIONIVAINC' => 'importe_adjudicacion',
        'IMPORTE' => 'importe_adjudicacion',
        'IMPORTELICITACIONIVAINC' => 'importe_licitacion',

        // Fechas
        'FECHADEADJUDICACION' => 'fecha_adjudicacion',
        'FECHAAPROBACION' => 'fecha_adjudicacion',
        'FECHAPRO' => 'fecha_adjudicacion',
        'FAPROBACION' => 'fecha_adjudicacion',
        'FECHADEINSCRIPCION' => 'fecha_inscripcion',
        'FCHCOMUNICREG' => 'fecha_inscripcion',
        'FINSCRIPCION' => 'fecha_inscripcion',
        'FECONTAB' => 'fecha_inscripcion',

        // Otros
        'PLAZO' => 'plazo',
        'NLICITADORESPARTICIPANTES' => 'num_ofertas',
    ];

    /**
     * Tipos de contrato del CSV → etiquetas canónicas de config('contratacion.tipos_contrato').
     */
    private const TIPO_CONTRATO_MAP = [
        'CONTRATO DE SUMINISTROS' => 'Suministros',
        'SUMINISTROS' => 'Suministros',
        'SUMINISTRO' => 'Suministros',
        'CONTRATO DE SERVICIOS' => 'Servicios',
        'SERVICIOS' => 'Servicios',
        'SERVICIO' => 'Servicios',
        'CONTRATO DE CONSULTORIA Y ASISTENCIA' => 'Servicios',
        'CONTRATO DE OBRAS' => 'Obras',
        'OBRAS' => 'Obras',
        'CONTRATO PRIVADO' => 'Privado',
        'PRIVADO' => 'Privado',
        'CONTRATO ADMINISTRATIVO ESPECIAL' => 'Administrativo especial',
        'ADMINISTRATIVO ESPECIAL' => 'Administrativo especial',
        'CONTRATO DE GESTION DE SERVICIOS PUBLICO' => 'Gestión de Servicios Públicos',
        'CONTRATO DE GESTION DE SERVICIOS PUBLICOS' => 'Gestión de Servicios Públicos',
        'CONCESION DE SERVICIOS' => 'Gestión de Servicios Públicos',
        'OTROS' => 'Otros',
    ];

    /**
     * URL del dataset de origen. Es la única URL real disponible: el CSV no trae
     * enlace por contrato, así que la trazabilidad apunta al conjunto de datos.
     */
    private string $datasetUrl;

    public function __construct(string $datasetUrl = 'https://datos.madrid.es/dataset/300253-0-contratos-actividad-menores')
    {
        $this->datasetUrl = $datasetUrl;
    }

    /**
     * Parsea una fila del CSV (array asociativo tal cual lo entrega league/csv).
     *
     * Devuelve null si la fila no es aprovechable (sin identificador o vacía).
     *
     * @param  array<string, string|null>  $record
     * @return array<string, mixed>|null
     */
    public function parse(array $record): ?array
    {
        $row = $this->canonicalize($record);

        $registro = $this->clean($row['registro'] ?? null);
        $expediente = $this->clean($row['expediente'] ?? null);

        // Clave natural: nº de registro de contrato; si el fichero no lo trae
        // (esquemas 2015-2017), el nº de expediente.
        $clave = $registro ?? $expediente;
        if ($clave === null) {
            return null;
        }

        $objeto = $this->clean($row['objeto'] ?? null);
        if ($objeto === null) {
            return null;
        }

        $centro = $this->clean($row['centro'] ?? null)
            ?? $this->clean($row['organismo_contratante'] ?? null)
            ?? $this->clean($row['organo'] ?? null);

        // Adjudicatario: normalmente hay columna propia de NIF, pero el portal ya
        // publica en otros recursos el NIF embebido entre paréntesis dentro del
        // nombre ("(B85893493) AENOR CONOCIMIENTO, S.L.U."). Se contempla como
        // respaldo para que un cambio de esquema no deje los contratos sin NIF.
        [$nifAdjudicatario, $nombreAdjudicatario] = $this->splitAdjudicatario(
            $row['nif'] ?? null,
            $row['adjudicatario'] ?? null
        );

        $importeAdjConIva = $this->parseImporte($row['importe_adjudicacion'] ?? null);
        $importeLicConIva = $this->parseImporte($row['importe_licitacion'] ?? null);

        $fechaInscripcion = $this->parseDate($row['fecha_inscripcion'] ?? null);
        $fechaAdjudicacion = $this->parseDate($row['fecha_adjudicacion'] ?? null);

        return [
            // La clave la completa el comando (necesita desambiguar duplicados de origen).
            'clave_natural' => $clave,
            'expediente' => $expediente ?? $registro,
            'objeto' => $objeto,

            // Órgano: NIF imputado del Ayuntamiento (ver docblock de la clase).
            'nif_organo' => self::NIF_AYUNTAMIENTO,
            'nombre_organo' => self::NOMBRE_AYUNTAMIENTO,
            'dir3' => self::DIR3_AYUNTAMIENTO,
            'tipo_organo' => 'Entidad Local',

            'tipo_contrato' => $this->mapTipoContrato($row['tipo'] ?? null),
            'procedimiento' => 'Contrato menor',
            'es_menor' => true,

            // Importes: el dataset publica sólo importes CON IVA.
            'importe_licitacion_con_iva' => $importeLicConIva,
            'importe_licitacion' => $this->sinIva($importeLicConIva),
            'importe_adjudicacion_con_iva' => $importeAdjConIva,
            'importe_adjudicacion' => $this->sinIva($importeAdjConIva),
            'moneda' => 'EUR',

            'nif_adjudicatario' => $nifAdjudicatario,
            'nombre_adjudicatario' => $nombreAdjudicatario,
            'pais_adjudicatario' => 'ES',
            'es_pyme' => $this->parsePyme($row['pyme'] ?? null),

            'cpv' => null,             // No existe en el dataset.
            'nuts' => self::NUTS_MADRID, // Imputado: municipio de Madrid.
            'lugar_ejecucion' => $centro,
            'ciudad_ejecucion' => 'Madrid',
            'num_ofertas' => $this->parseNumOfertas($row['num_ofertas'] ?? null),
            'duracion' => $this->parsePlazo($row['plazo'] ?? null),

            // Trazabilidad: no hay URL por contrato, se apunta al dataset de origen.
            'url_placsp' => $this->datasetUrl,

            'fecha_publicacion' => $fechaInscripcion ?? $fechaAdjudicacion,
            'fecha_adjudicacion' => $fechaAdjudicacion,
        ];
    }

    /**
     * Construye el placsp_id definitivo.
     *
     * `$sufijo` sólo se usa cuando la clave natural está repetida dentro del mismo
     * fichero (hay duplicados reales en origen); es un hash corto del contenido de
     * la fila, de modo que dos filas distintas con el mismo nº de registro no se
     * pisen y una reimportación del mismo fichero produzca los mismos ids.
     *
     * @param  array<string, mixed>  $data
     */
    public function makePlacspId(array $data, bool $duplicado = false): string
    {
        $id = 'AYTMAD-'.$data['clave_natural'];

        if ($duplicado) {
            $id .= '-'.mb_substr(md5(json_encode($data) ?: serialize($data)), 0, 6);
        }

        // La columna placsp_id es varchar(100).
        return mb_substr($id, 0, 100);
    }

    /**
     * Reindexa la fila por nombre canónico usando los alias de cabecera.
     *
     * @param  array<string, string|null>  $record
     * @return array<string, string|null>
     */
    private function canonicalize(array $record): array
    {
        $row = [];

        foreach ($record as $header => $value) {
            $key = self::HEADER_ALIASES[$this->normalizeHeader((string) $header)] ?? null;
            if ($key === null) {
                continue;
            }

            // Primer alias que aporta valor gana (evita que una columna vacía
            // posterior pise a la buena). isset() ya descarta el caso null.
            if (! isset($row[$key]) || trim($row[$key]) === '') {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    /**
     * Normaliza una cabecera: sin acentos, sin signos, sin espacios, en mayúsculas.
     * 'Nº exped. Adm.' → 'NEXPEDADM'; 'ORG_CONTRATACIÓN' → 'ORGCONTRATACION'.
     */
    private function normalizeHeader(string $header): string
    {
        $header = mb_strtoupper(trim($header));

        $header = strtr($header, [
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A',
            'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'Ñ' => 'N', 'Ç' => 'C',
        ]);

        return (string) preg_replace('/[^A-Z0-9]/u', '', $header);
    }

    /**
     * Importe europeo con posible símbolo de euro: "15.808,00€", "60,00 €", "405,35".
     */
    private function parseImporte(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        // Quita €, "EUR", espacios normales y no separables, y cualquier otro resto
        // no numérico: el dataset alterna "15.808,00€", "60,00 €" y "405,35".
        $value = (string) preg_replace('/[^0-9,.\-]/u', '', trim($value));
        if ($value === '') {
            return null;
        }

        // Formato europeo: miles con punto, decimales con coma.
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $value)) {
            $value = str_replace('.', '', $value);
        }
        $value = str_replace(',', '.', $value);

        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    /**
     * Estima el importe sin IVA a partir del importe con IVA (tipo general 21%).
     * Mismo criterio que CastillaYLeonParser: el dataset no publica base imponible.
     */
    private function sinIva(?float $conIva): ?float
    {
        return $conIva === null ? null : round($conIva / self::IVA_GENERAL, 2);
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // El dataset alterna año de 4 y de 2 dígitos SIN avisar en la cabecera:
        // 2022 y 2025 vienen como "03/01/25" y el resto como "03/01/2025". Sin
        // esta rama, esos dos ficheros (11.525 filas) entraban sin fecha alguna.
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})(?!\d)#', $value, $m)) {
            $year = (int) $m[3];
            $month = (int) $m[2];
            $day = (int) $m[1];

            if (mb_strlen($m[3]) === 2) {
                // Pivote estándar: 00-69 → 20xx, 70-99 → 19xx. El dataset sólo
                // cubre 2015-actualidad, así que en la práctica siempre es 20xx.
                $year += $year <= 69 ? 2000 : 1900;
            }

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return null;
    }

    private function parsePyme(?string $value): ?bool
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }

        // "N0" (con cero) aparece de verdad en el dataset.
        return match (mb_strtoupper($value)) {
            'SI', 'S', 'SÍ', 'YES', 'TRUE', '1' => true,
            'NO', 'N', 'N0', 'FALSE', '0' => false,
            default => null,
        };
    }

    private function parseNumOfertas(?string $value): ?int
    {
        $value = $this->clean($value);
        if ($value === null || ! preg_match('/^\d+$/', $value)) {
            return null;
        }

        $num = (int) $value;

        // La columna es unsignedSmallInteger.
        return $num >= 0 && $num <= 65535 ? $num : null;
    }

    /**
     * PLAZO viene en meses con decimales ("12,00", "0,50").
     */
    private function parsePlazo(?string $value): ?string
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }

        if (! preg_match('/^\d+([.,]\d+)?$/', $value)) {
            return mb_substr($value, 0, 100);
        }

        $meses = (float) str_replace(',', '.', $value);
        if ($meses <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format($meses, 2, ',', ''), '0'), ',').' meses';
    }

    /**
     * Separa NIF y razón social del adjudicatario.
     *
     * Si la columna de NIF viene vacía y el nombre trae el identificador entre
     * paréntesis al principio, se extrae de ahí y se limpia el nombre.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function splitAdjudicatario(?string $nif, ?string $nombre): array
    {
        $nifLimpio = $this->cleanNif($nif);
        $nombreLimpio = $this->clean($nombre);

        if ($nifLimpio === null && $nombreLimpio !== null
            && preg_match('/^\(\s*([A-Za-z0-9]{5,20})\s*\)\s*(.*)$/u', $nombreLimpio, $m)) {
            $nifLimpio = $this->cleanNif($m[1]);
            $nombreLimpio = $this->clean($m[2]) ?? $nombreLimpio;
        }

        return [$nifLimpio, $nombreLimpio];
    }

    private function cleanNif(?string $nif): ?string
    {
        $nif = $this->clean($nif);
        if ($nif === null) {
            return null;
        }

        $nif = mb_strtoupper((string) preg_replace('/[\s.\-]/', '', $nif));

        // Descarta basura y valores que no caben en la columna (varchar 20).
        if (mb_strlen($nif) < 5 || mb_strlen($nif) > 20) {
            return null;
        }

        return $nif;
    }

    private function mapTipoContrato(?string $tipo): ?string
    {
        $tipo = $this->clean($tipo);
        if ($tipo === null) {
            return null;
        }

        $key = $this->normalizeForMap($tipo);

        return self::TIPO_CONTRATO_MAP[$key] ?? $tipo;
    }

    /**
     * Mayúsculas sin acentos, conservando espacios (para las claves del mapa de tipos).
     */
    private function normalizeForMap(string $value): string
    {
        $value = mb_strtoupper(trim($value));
        $value = strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        return (string) preg_replace('/\s+/', ' ', $value);
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace("\u{00A0}", ' ', $value));
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return $value === '' ? null : $value;
    }
}
