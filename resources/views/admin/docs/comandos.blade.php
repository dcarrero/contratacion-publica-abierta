<x-layouts.admin :title="'Comandos Artisan'">
    @php $adminActive = 'docs'; @endphp

    <div class="flex items-center gap-2 mb-6">
        <a href="{{ route('admin.docs') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Docs</a>
        <span class="text-gray-300">/</span>
        <h1 class="text-2xl font-bold text-gray-800">Comandos Artisan</h1>
    </div>

    <div class="bg-white rounded-lg shadow-sm border p-6 prose prose-sm max-w-none">
        <h2>Sincronización PLACSP</h2>
        <table>
            <thead><tr><th>Comando</th><th>Descripción</th></tr></thead>
            <tbody>
                <tr><td><code>placsp:sync-licitaciones</code></td><td>Sync incremental de licitaciones desde feed Atom PLACSP</td></tr>
                <tr><td><code>placsp:sync-licitaciones --full</code></td><td>Sync completo (todas las páginas)</td></tr>
                <tr><td><code>placsp:sync-licitaciones --since=2026-01-01</code></td><td>Desde fecha específica</td></tr>
                <tr><td><code>placsp:sync-licitaciones --dry-run</code></td><td>Solo parsear, no insertar</td></tr>
                <tr><td><code>placsp:sync-menores</code></td><td>Sync contratos menores (mismas opciones)</td></tr>
                <tr><td><code>placsp:sync-organos storage/app/organos.csv</code></td><td>Importar organismos desde CSV</td></tr>
            </tbody>
        </table>

        <h2>Fuentes regionales</h2>
        <table>
            <thead><tr><th>Comando</th><th>Región</th><th>Notas</th></tr></thead>
            <tbody>
                <tr><td><code>regional:sync-cat</code></td><td>Catalunya</td><td>API Socrata, ~1.69M registros</td></tr>
                <tr><td><code>regional:sync-anda</code></td><td>Andalucía</td><td>CKAN API, descarga auto CSVs</td></tr>
                <tr><td><code>regional:sync-cyl</code></td><td>Castilla y León</td><td>Opendatasoft API</td></tr>
                <tr><td><code>regional:sync-eusk</code></td><td>País Vasco</td><td>JSON anuales + XML detallado</td></tr>
                <tr><td><code>regional:enrich-eusk</code></td><td>País Vasco</td><td>Enriquecimiento via XML</td></tr>
                <tr><td><code>regional:sync-mad</code></td><td>Madrid</td><td>ATOM CODICE</td></tr>
                <tr><td><code>regional:sync-val</code></td><td>Valencia</td><td>CSV anuales</td></tr>
                <tr><td><code>regional:sync-can</code></td><td>Canarias</td><td>CSV único</td></tr>
                <tr><td><code>regional:sync-ara</code></td><td>Aragón</td><td>CSV dinámico via CGI</td></tr>
                <tr><td><code>regional:sync-ast</code></td><td>Asturias</td><td>CSV anuales 2019-2024</td></tr>
            </tbody>
        </table>

        <h2>Importación y datos</h2>
        <table>
            <thead><tr><th>Comando</th><th>Descripción</th></tr></thead>
            <tbody>
                <tr><td><code>bootstrap:csv {file}</code></td><td>Importar datos iniciales desde CSV BQuant</td></tr>
                <tr><td><code>stats:recalculate</code></td><td>Recalcular todas las estadísticas</td></tr>
                <tr><td><code>stats:recalculate --entity=charts</code></td><td>Solo gráficas (charts.json)</td></tr>
                <tr><td><code>stats:recalculate --entity=informes</code></td><td>Solo informes</td></tr>
                <tr><td><code>nif:normalize</code></td><td>Normalizar NIFs a uppercase + fusionar duplicados</td></tr>
                <tr><td><code>nif:merge-masked</code></td><td>Fusionar adjudicatarios con NIF enmascarado</td></tr>
                <tr><td><code>anomalias:detectar</code></td><td>Detectar fraccionamientos, concentración, picos</td></tr>
                <tr><td><code>alertas:enviar</code></td><td>Enviar digesto de alertas a suscriptores</td></tr>
            </tbody>
        </table>

        <h2>Mantenimiento</h2>
        <table>
            <thead><tr><th>Comando</th><th>Descripción</th></tr></thead>
            <tbody>
                <tr><td><code>cache:clear</code></td><td>Limpiar caché de aplicación</td></tr>
                <tr><td><code>config:clear</code></td><td>Limpiar caché de configuración</td></tr>
                <tr><td><code>data:fix-quality</code></td><td>Corregir fechas absurdas, importes, NUTS</td></tr>
                <tr><td><code>ine:sync-poblacion</code></td><td>Actualizar datos de población INE</td></tr>
            </tbody>
        </table>
    </div>
</x-layouts.admin>
