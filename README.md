# Contratación Abierta — España

Portal de transparencia en contratación pública de toda España. Recopila, normaliza y presenta contratos públicos de todas las administraciones: estatal, comunidades autónomas, diputaciones, ayuntamientos y sector público. Cada contrato enlaza a su fuente oficial en la [Plataforma de Contratación del Sector Público (PLACSP)](https://contrataciondelestado.es). Los adjudicatarios se normalizan por NIF/CIF para evitar duplicados.

> **Aviso importante**: los datos de este proyecto proceden de fuentes públicas y se normalizan
> automáticamente, por lo que **pueden contener errores o no estar completamente actualizados**.
> Para cualquier uso oficial o legal, **consulte siempre las fuentes oficiales** (PLACSP y los
> portales de datos abiertos de cada administración). Este proyecto no sustituye a las fuentes oficiales.

## Consulta pública

Si no quieres montarlo tú mismo, podrás consultar los datos en **[contratacionabierta.com](https://contratacionabierta.com/)**.
*(Próximamente: la web final con los datos para consulta pública.)*

## Datos actuales

- **~8,2 millones** de contratos públicos
- **~17.100** organismos contratantes
- **~605.000** adjudicatarios
- **19 CCAA** cubiertas (varias con fuente regional propia + resto vía PLACSP)
- Múltiples fuentes de datos activas (ver más abajo)

## Stack técnico

- **Backend**: Laravel 12 (PHP 8.3+)
- **BD desarrollo**: SQLite
- **BD producción**: PostgreSQL 17 (FTS tsvector + GIN, búsqueda en español con stemming)
- **Frontend**: Blade + Livewire 4 + Alpine.js, Tailwind CSS 4, Chart.js, Leaflet, D3.js
- **PDF**: DomPDF (barryvdh/laravel-dompdf)
- **Datos**: Feeds Atom PLACSP (CODICE), datasets BQuant, APIs/CSV regionales
- **Docker**: FrankenPHP + PostgreSQL 17

## Requisitos

- PHP 8.3+
- Composer 2.x
- Node.js 20+ / npm 10+
- SQLite (desarrollo) o PostgreSQL 17+ (producción)

## Instalación

```bash
git clone https://github.com/dcarrero/contratacion-publica-abierta.git
cd contratacion-publica-abierta
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve    # http://localhost:8000
npm run dev          # Vite dev server
```

## Docker

```bash
cp .env.docker.example .env.docker
# Edita .env.docker: rellena contraseñas, APP_URL, ADMIN_ALLOWED_IPS, etc.
docker compose up -d --build
docker compose exec app composer install
docker compose exec app npm install && npm run build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

App en `http://localhost:8080`.

## Reproducir los datos (scraping/sincronización)

```bash
# Estatal (PLACSP)
php artisan placsp:sync-licitaciones
php artisan placsp:sync-menores

# Regionales (cada una descarga automáticamente)
php artisan regional:sync-cat   # Cataluña
php artisan regional:sync-anda  # Andalucía
# ... (ver tabla de fuentes; php artisan list para todas)

# Recalcular estadísticas/JSON del dashboard
php artisan stats:recalculate
```

## Fuentes de datos

### PLACSP (nivel estatal)

| Fuente | Tipo | Frecuencia |
|--------|------|------------|
| [Licitaciones](https://contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3.atom) | Atom/XML CODICE | Diaria |
| [Contratos Menores](https://contrataciondelsectorpublico.gob.es/sindicacion/sindicacion_1143/contratosMenoresPerfilesContratantes.atom) | Atom/XML CODICE | Semanal |

### Fuentes regionales (datos abiertos de cada CCAA)

| CCAA | Comando | Tipo |
|------|---------|------|
| Cataluña | `regional:sync-cat` | Socrata API |
| Andalucía | `regional:sync-anda` | CKAN CSV |
| País Vasco | `regional:sync-eusk` | JSON + XML |
| Asturias | `regional:sync-ast` | CSV anuales |
| Canarias | `regional:sync-can` | CSV |
| Valencia | `regional:sync-val` | CSV anuales |
| Aragón | `regional:sync-ara` | CSV dinámico |
| Castilla y León | `regional:sync-cyl` | Opendatasoft |
| Madrid | `regional:sync-mad` | Atom CODICE |
| Región de Murcia | `regional:sync-mur` | CSV (CARM) |

## Principios

- **NIF como verdad**: organismos y adjudicatarios se identifican por NIF, nunca por nombre.
- **Trazabilidad**: todo contrato guarda `placsp_id`, `url_placsp` y su fuente de datos.
- **Historial**: las actualizaciones de contratos guardan la versión anterior.
- **Compatibilidad BD**: migraciones compatibles SQLite + PostgreSQL.

## Funcionalidades

Búsqueda full-text, mapa coroplético (Leaflet), análisis y gráficas (Chart.js), rankings con gasto
per cápita, grafo de relaciones (D3.js), detección de anomalías, alertas por email y RSS, e informes
por CCAA/anuales con exportación CSV y PDF.

## Referencias

- [Plataforma de Contratación del Sector Público (PLACSP)](https://contrataciondelestado.es) — fuente oficial estatal.
- Portales de datos abiertos de las CCAA (enlazados en cada comando regional).
- Especificación CODICE (formato de sindicación de PLACSP).
- [ContratacionAbierta.com](https://contratacionabierta.com/) — más portales libres y código para consultar
  datos públicos de forma sencilla, sin sufrir las webs oficiales (a menudo poco usables).

## Autor

**David Carrero Fernández-Baillo** — [@dcarrero](https://github.com/dcarrero).

Contribuciones bienvenidas vía issues y pull requests.

## Licencia

[MIT License](LICENSE) © 2026 David Carrero F-B. Los **datos** provienen de fuentes públicas oficiales;
revise los términos de cada fuente para su reutilización.
