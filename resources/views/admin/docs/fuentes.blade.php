<x-layouts.admin :title="'Fuentes de datos'">
    @php $adminActive = 'docs'; @endphp

    <div class="flex items-center gap-2 mb-6">
        <a href="{{ route('admin.docs') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Docs</a>
        <span class="text-gray-300">/</span>
        <h1 class="text-2xl font-bold text-gray-800">Fuentes de datos</h1>
    </div>

    <div class="bg-white rounded-lg shadow-sm border p-6 prose prose-sm max-w-none">
        <h2>PLACSP — Plataforma de Contratación del Sector Público</h2>
        <ul>
            <li><strong>Formato</strong>: Atom feed (especificación CODICE, 163 págs)</li>
            <li><strong>Licitaciones</strong>: <code>contrataciondelestado.es/sindicacion/sindicacion_643/</code></li>
            <li><strong>Menores</strong>: <code>contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1143/</code></li>
            <li><strong>Cobertura</strong>: toda España, ~4M contratos</li>
            <li><strong>ID</strong>: <code>placsp_id</code> (numérico extraído de URL)</li>
            <li><strong>Calidad</strong>: fecha 96.7%, importe 94%, NIF adj 95.1%, NUTS 99.8%</li>
        </ul>

        <h2>BQuant</h2>
        <ul>
            <li><strong>Formato</strong>: Parquet (965 MB) → CSV exportado</li>
            <li><strong>Registros</strong>: 8.69M (superset de PLACSP)</li>
            <li><strong>Aporte único</strong>: importe_adj_con_iva (PLACSP solo tiene sin IVA)</li>
            <li><strong>Nuevos importables</strong>: 1.59M con NIF</li>
        </ul>

        <h2>Catalunya</h2>
        <ul>
            <li><strong>Formato</strong>: API Socrata (JSON paginado)</li>
            <li><strong>URL</strong>: <code>analisi.transparenciacatalunya.cat/resource/ybgg-dgi6.json</code></li>
            <li><strong>Registros</strong>: ~1.69M</li>
            <li><strong>ID</strong>: <code>CAT-{dir3}-{expediente}</code></li>
            <li><strong>Nota</strong>: NIFs enmascarados por privacidad (88% de los enmascarados son catalanes)</li>
        </ul>

        <h2>Andalucía</h2>
        <ul>
            <li><strong>Formato</strong>: CSV (ISO-8859-1, auto-conversión UTF-8)</li>
            <li><strong>API</strong>: CKAN (<code>juntadeandalucia.es/datosabiertos/portal/</code>)</li>
            <li><strong>Registros</strong>: ~693K (2018-2025)</li>
            <li><strong>ID</strong>: <code>ANDA-{año}-{id}</code></li>
            <li><strong>Calidad</strong>: excelente (fecha 100%, importe 99.9%, NIF 99.9%), pero 0% CPV</li>
        </ul>

        <h2>País Vasco</h2>
        <ul>
            <li><strong>Formato</strong>: JSON anuales + XML individual para detalle</li>
            <li><strong>URL</strong>: <code>opendata.euskadi.eus</code></li>
            <li><strong>Registros</strong>: ~526K (2018-2026)</li>
            <li><strong>ID</strong>: <code>EUSK-{expediente}</code></li>
            <li><strong>Nota</strong>: JSON no tiene importes ni NIF adj; requiere enriquecimiento XML</li>
        </ul>

        <h2>Castilla y León</h2>
        <ul>
            <li><strong>Formato</strong>: CSV (Opendatasoft API)</li>
            <li><strong>Registros</strong>: ~128K (menores + ordinarios + SACYL)</li>
            <li><strong>ID</strong>: <code>CYL-{expediente}</code></li>
            <li><strong>Nota</strong>: importe con IVA → se calcula sin IVA (/1.21)</li>
        </ul>

        <h2>Asturias</h2>
        <ul>
            <li><strong>Formato</strong>: CSV anuales (delimitador §, ISO-8859-1)</li>
            <li><strong>URL</strong>: <code>descargas.asturias.es</code></li>
            <li><strong>Registros</strong>: ~375K (2019-2024)</li>
            <li><strong>ID</strong>: <code>AST-{inscripcion}</code></li>
            <li><strong>Calidad</strong>: MUY ALTA, 91 columnas, NIF adj 100% (2022+)</li>
        </ul>

        <h2>Otras fuentes</h2>
        <table>
            <thead><tr><th>Región</th><th>Formato</th><th>Registros aprox.</th><th>ID prefijo</th></tr></thead>
            <tbody>
                <tr><td>Madrid</td><td>ATOM CODICE</td><td>~930 recientes</td><td>MAD-</td></tr>
                <tr><td>Valencia</td><td>CSV anuales</td><td>Variable</td><td>VAL-</td></tr>
                <tr><td>Canarias</td><td>CSV único</td><td>~222K</td><td>CAN-</td></tr>
                <tr><td>Aragón</td><td>CSV dinámico</td><td>~152K</td><td>ARA-</td></tr>
            </tbody>
        </table>
    </div>
</x-layouts.admin>
