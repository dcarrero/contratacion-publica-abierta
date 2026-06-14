<x-layouts.admin :title="'Arquitectura'">
    @php $adminActive = 'docs'; @endphp

    <div class="flex items-center gap-2 mb-6">
        <a href="{{ route('admin.docs') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Docs</a>
        <span class="text-gray-300">/</span>
        <h1 class="text-2xl font-bold text-gray-800">Arquitectura</h1>
    </div>

    <div class="bg-white rounded-lg shadow-sm border p-6 prose prose-sm max-w-none">
        <h2>Stack técnico</h2>
        <ul>
            <li><strong>Backend</strong>: Laravel 12+ (PHP 8.5+)</li>
            <li><strong>BD desarrollo</strong>: SQLite (zero config)</li>
            <li><strong>BD producción</strong>: MariaDB 10.11+</li>
            <li><strong>Frontend</strong>: Blade + Livewire 4 + Alpine.js, Tailwind CSS 4</li>
            <li><strong>Gráficas</strong>: Chart.js 4.x (CDN)</li>
            <li><strong>Mapas</strong>: Leaflet (CDN)</li>
            <li><strong>Grafos</strong>: D3.js v7 (CDN)</li>
            <li><strong>PDFs</strong>: DomPDF (barryvdh/laravel-dompdf)</li>
        </ul>

        <h2>Flujo de datos</h2>
        <pre><code>PLACSP Atom feeds / BQuant CSV / Regionales
        |
  Artisan Commands (sync / bootstrap)
        |
  ContratoImporter service (lógica central)
        |
  ┌─────────────────────────────────────────┐
  │  Resolver Organismo por NIF → firstOrCreate  │
  │  Resolver Adjudicatario NIF → firstOrCreate  │
  │  Upsert Contrato placsp_id → create/update   │
  └─────────────────────────────────────────┘
        |
  SQLite/MariaDB
        |
  Controllers → Blade + Livewire → Usuario</code></pre>

        <h2>Capas</h2>
        <p><strong>Commands</strong> → <strong>Services</strong> → <strong>Models</strong>. Jobs para operaciones pesadas. Actions para lógica reutilizable aislada.</p>

        <h2>Principio fundamental: NIF como verdad</h2>
        <ul>
            <li><strong>Organismos</strong>: <code>nif</code> UNIQUE. Nunca duplicar por variante de nombre.</li>
            <li><strong>Adjudicatarios</strong>: <code>nif</code> UNIQUE. Variantes en <code>adjudicatario_aliases</code>.</li>
            <li><strong>Contratos</strong>: <code>placsp_id</code> UNIQUE. Updates → historial.</li>
        </ul>

        <h2>Convenciones de naming</h2>
        <ul>
            <li>Clases y métodos en <strong>inglés</strong>, campos BD y UI en <strong>español</strong></li>
            <li>Models singular español: <code>Contrato</code>, <code>Organismo</code>, <code>Adjudicatario</code></li>
            <li>Tables plural español: <code>contratos</code>, <code>organismos</code>, <code>adjudicatarios</code></li>
            <li>Commands con prefijo por dominio: <code>placsp:sync-*</code>, <code>stats:*</code>, <code>regional:*</code></li>
        </ul>

        <h2>Estructura de directorios clave</h2>
        <pre><code>app/
├── Console/Commands/        # Artisan commands por dominio
├── Http/Controllers/        # Controllers públicos
├── Http/Controllers/Admin/  # Panel admin
├── Models/                  # Eloquent models
├── Services/                # ContratoImporter, InformeDataBuilder
│   └── Regional/            # Parsers regionales (Cat, Anda, CyL...)
└── Actions/                 # Lógica reutilizable

storage/app/
├── mapa-stats/              # JSONs pre-computados (charts, rankings, grafo)
└── placsp-zips/             # ZIPs descargados de PLACSP y regionales</code></pre>

        <h2>Geografía</h2>
        <ul>
            <li>Tabla <code>comunidades_autonomas</code>: 19 CCAA con código INE y NUTS</li>
            <li>Tabla <code>provincias</code>: 52 provincias con FK a CCAA</li>
            <li><code>es_clm</code> se deriva de NUTS (prefijo ES42) en ContratoImporter</li>
        </ul>
    </div>
</x-layouts.admin>
