# Changelog

Todos los cambios relevantes de la versión pública de este proyecto se documentan aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/) y versionado semántico.

## [1.9.3] - 2026-11-01

### Corregido

- **Euskadi**: el enriquecimiento no extraía nunca el CPV ni el importe de licitación, porque
  buscaba etiquetas que el XML de detalle no usa. Ahora lee el bloque `<cpvs>` (primero los
  CPV principales) y el presupuesto de `<budgetWithoutVAT>` cuando es el del contrato, sin
  confundirlo con el precio adjudicado.
- **PostgreSQL en Docker**: `oom_score_adj` para que, si la máquina se queda sin memoria, el
  sistema no elija la base de datos.

### Cambiado

- **Enriquecimiento incremental de Euskadi**: cada XML consultado se anota en la tabla nueva
  `contrato_enriquecimientos`, y la pasada semanal solo pide los contratos nuevos, los que la
  sincronización ha modificado después y los que fallaron. Antes repetía cada semana unos
  85.000 XML. `--todos` fuerza una pasada completa y `--json=` usa un fichero local.

## [1.9.2] - 2026-10-31

### Añadido

- **Región de Murcia en el scheduler**: sincronización semanal (sábados) de los tres últimos
  ejercicios. Los años que la CARM aún no ha publicado se saltan sin error.

### Corregido

- **Castilla y León**: la sync fallaba con "invalid multibyte sequence" aunque el CSV fuese
  UTF-8 válido. El filtro `convert.iconv` del stream parte los caracteres acentuados entre
  bloques con el iconv de glibc; ahora se lee sin filtro y cada campo pasa por `mb_scrub`.
- **Comunitat Valenciana**: el año en curso no se importaba porque los identificadores de
  recurso estaban fijados hasta 2025. Ahora se obtienen de la API de CKAN y se descarga el
  CSV estático, mucho más rápido que el volcado del datastore. También se sincroniza el año
  anterior.
- **Euskadi**: el enriquecimiento con el XML de detalle superaba el límite de 65.535
  parámetros de PostgreSQL y fallaba; ahora consulta por tandas. Además, los XML en
  ISO-8859-1 se convierten a UTF-8 antes de extraer los campos.

## [1.9.1] - 2026-10-31

### Añadido

- **Servicio `scheduler` en Docker**: un contenedor dedicado ejecuta `schedule:work`, de modo
  que las sincronizaciones diarias y semanales, el recálculo de estadísticas y las alertas se
  lanzan solos sin configurar cron en el anfitrión.
- **Copia de seguridad diaria** con `db:backup`: volcado de PostgreSQL en formato custom
  comprimido con zstd, validado antes de darlo por bueno y con rotación que solo toca las
  copias que genera el propio comando.
- **Ficheros de despliegue** en `deploy/` para separar la base de datos y la aplicación en dos
  máquinas.

### Cambiado

- **Histórico a partir de 2018**: los años anteriores a la Ley 9/2017, cuando publicar en la
  Plataforma de Contratación era voluntario, se agrupan en un bloque plegable. Mezclados con
  el resto parecían una caída de actividad que nunca existió. El año de corte se configura en
  `anio_cobertura_completa`.

### Corregido

- `app` y `scheduler` comparten ahora la misma imagen de Docker. Antes cada uno construía la
  suya y una podía quedarse desfasada, como pasó con `pg_dump`, que faltaba en el scheduler.

## [1.9.0] - 2026-09-07

### Añadido

- **Fichas centradas en el año en curso**: las páginas de empresa, organismo y comunidad
  autónoma muestran ahora la actividad del año con su variación respecto al anterior, en vez
  de mezclar todos los ejercicios en un único total. La comparación se hace sobre el mismo
  tramo de meses (por ejemplo enero–agosto contra enero–agosto), para no medir un año
  incompleto contra uno entero.
- **Páginas de histórico** (`/empresas/{nif}/historico` y equivalentes en organismos,
  administraciones y a nivel nacional): evolución año a año con variación interanual,
  comparativa mes a mes entre ejercicios, matriz de actividad por mes y año, y récords.
- **Vistas por año concreto** (`/empresas/{nif}/2024`), enlazables e indexables.
- **Red de relaciones** por entidad: grafo de las contrapartes directas y de quién más
  contrata con ellas, en su propia página.
- **Aviso de entidad sin actividad**: cuando una empresa u organismo no tiene contratos en el
  año en curso se indica de forma explícita y se ofrece su histórico, en lugar de mostrar una
  ficha vacía.
- **Gráficas de tendencia** en los rankings de la portada: cada fila incluye la evolución
  mensual, para distinguir la actividad sostenida de un pico puntual.
- **Nuevas fuentes de datos**: canal de plataformas agregadas de la Plataforma de Contratación
  del Sector Público (Cataluña, Euskadi, Andalucía, Madrid, Galicia, Navarra y La Rioja) con
  sincronización diaria, y contratos menores del Ayuntamiento de Madrid desde su portal de
  datos abiertos.
- **Fusión de contratos duplicados** entre fuentes distintas (`data:merge-duplicados`), para
  los casos en que el mismo contrato se publica en dos portales con identificadores propios.

### Cambiado

- **Notación de importes unificada**: se elimina la escala de tres niveles que hacía convivir
  "5,7 M€" con "11,3 Md€" —y con variantes distintas según la página—. Todo lo que llega al
  millón se expresa en millones y por debajo se dan los euros completos.
- **Jerarquía visual**: las páginas distinguen ahora el bloque principal, las secciones de
  apoyo y las notas, en vez de presentarlo todo con el mismo peso. Titulares con tipografía
  propia y escala de encabezados coherente.
- **Tablas de contratos usables en móvil**: por debajo de pantalla pequeña cada contrato se
  presenta como ficha apilada, sin desplazamiento horizontal, y muestra el organismo o
  adjudicatario que antes quedaba oculto.
- **Nombres alternativos de adjudicatario colapsados**: las empresas con cientos de variantes
  de nombre (hasta 746) ya no empujan el contenido fuera de la pantalla.
- Cifras de ancho fijo en tablas y paneles, para poder compararlas verticalmente.

### Corregido

- **Actualización de datos incompleta**: la importación de archivos anuales reutilizaba la
  copia local sin comprobar si el fichero publicado había cambiado. Como el archivo del año en
  curso se regenera a diario, el proceso reprocesaba una copia antigua e informaba de "0
  nuevos". Ahora se revalida contra el origen, y los archivos de años cerrados siguen
  sirviéndose de la copia local.
- **Búsqueda de contratos mucho más rápida**: el orden por defecto obligaba a recorrer la tabla
  completa. Con un índice sobre la expresión de ordenación, la consulta pasa de segundos a
  milisegundos.
- **Canal de sindicación obsoleto** en la configuración, que respondía con una página de error
  en lugar de datos.
- Fechas con año de dos dígitos en los ficheros del Ayuntamiento de Madrid, que dejaban sin
  fecha a una parte de los contratos importados.

## [1.8.0] - 2026-07-10

### Añadido

- **Accesibilidad (WCAG 2.2 AA)**: enlace "Saltar al contenido", menús operables por teclado (con
  `aria-expanded` y cierre con Escape), tablas de datos con cabeceras semánticas (`scope`) y descripción,
  anuncio de resultados de búsqueda para lectores de pantalla (`aria-live`), foco visible en todos los
  controles, mejor contraste de textos secundarios, iconos decorativos ocultos a la tecnología asistiva y
  un único encabezado principal por página (panel y mapa).

### Cambiado

- **Búsquedas y filtros más rápidos**: el filtro por año y por comunidad autónoma ahora usan el índice de
  la base de datos (rangos en vez de funciones sobre la columna), evitando recorrer toda la tabla.
- **Exportación CSV más eficiente**: la descarga de contratos deja de hacer una consulta por fila
  (organismo y adjudicatario se resuelven con un JOIN), reduciendo drásticamente la carga en exportaciones grandes.
- **Importación por lotes** en el bootstrap de datos: inserción masiva resiliente (una fila defectuosa se
  aísla sin abortar el lote), acelerando la carga inicial del dataset.

### Seguridad

- **Escapado de datos estructurados (JSON-LD)**: los nombres de organismos y empresas se escapan de forma
  segura, evitando que un nombre malicioso pueda inyectar contenido en la página.
- **Cabecera Content-Security-Policy** (modo report-only) como contención adicional frente a XSS.
- **Límite de intentos** en el acceso al panel de administración (protección frente a fuerza bruta).

## [1.7.0] - 2026-06-15

### Añadido

- **Gráfica de evolución anual** en la radiografía de provincia: barras (contratos) y línea (importe) con
  doble eje, además de la tabla de datos.

## [1.6.1] - 2026-06-15

### Añadido

- **Comando `radiografia:warm`** para precalentar la caché mensual de las radiografías de provincia
  (con `--years`, también las vistas anuales). Cubre además todo el comparador, que reutiliza la misma
  caché. Programado mensual.

### Corregido

- **Botón "Comparar" y menú móvil sin funcionar** en las páginas que no tienen componente Livewire: Alpine.js
  no se cargaba en ellas. Ahora se carga en todas las páginas, por lo que el comparador navega correctamente
  y el menú móvil responde.

## [1.6.0] - 2026-06-15

### Añadido

- **Comparador de provincias** (`/comparar`): compara dos provincias lado a lado (contratos, importe,
  importe por habitante, % de contratos menores, organismos, adjudicatarios) con el valor mayor resaltado
  y los principales adjudicatarios de cada una. Enlazado desde la radiografía y en el sitemap.
- **Datos estructurados (JSON-LD)**: identidad del sitio (Organization) y migas de navegación
  (BreadcrumbList) en radiografía, empresa, organismo y contrato, para resultados enriquecidos en buscadores.

### Cambiado

- **Metadatos SEO por página**: descripción única, enlace canónico y etiquetas Open Graph/Twitter en cada
  página (radiografía, empresa, organismo, contrato), mejorando los snippets y las previsualizaciones al
  compartir enlaces.

## [1.5.0] - 2026-06-15

### Añadido

- **Sitemap (`/sitemap.xml`) y `robots.txt`** para mejorar la indexación en buscadores: incluye las
  secciones, las radiografías por provincia y año, y los informes por CCAA. `robots.txt` referencia el
  sitemap usando el dominio configurado.

## [1.4.0] - 2026-06-15

### Añadido

- **Análisis en la ficha de organismo**: índice de concentración de proveedores (Herfindahl) y porcentaje
  de adjudicaciones sin concurrencia (una sola oferta), como señales de transparencia.

### Cambiado

- **URLs de la radiografía por año** más amigables para buscadores: el año va en la ruta
  (`/radiografia/cadiz/2025`) en lugar de como parámetro (`?year=2025`). La forma antigua redirige (301).

### Corregido

- **Rendimiento de las fichas de organismo y empresa**: el listado de "últimos contratos" ordenaba muchas
  filas sin índice adecuado (varios segundos en entidades grandes). Se añaden índices compuestos
  `(organismo_id, fecha_publicacion)` y `(adjudicatario_id, fecha_publicacion)`.

## [1.3.0] - 2026-06-15

### Añadido

- **Radiografía por año con comparación interanual**: en la radiografía de cada provincia se puede elegir
  un año (`?year=YYYY`) para ver sus cifras (contratos, importe, principales adjudicatarios y organismos,
  sectores) y una comparación con el año anterior (variación en contratos, importe y gasto por habitante).

## [1.2.2] - 2026-06-15

### Corregido

- **Rendimiento del resumen de anomalías** en los informes por CCAA: el cálculo usaba subconsultas
  anidadas con planes inestables (>10s); ahora emplea un semi-join sobre los organismos de la zona
  (~2s). Acelera la generación de los informes.

### Añadido

- Variables de entorno opcionales `SITIO_NOMBRE` / `SITIO_DOMINIO` / `SITIO_URL` documentadas en los
  `.env.example` para personalizar la marca del sitio (informes PDF, etc.) en autoalojamiento.

## [1.2.1] - 2026-06-14

### Corregido

- **Rendimiento de la Radiografía por provincia**: las páginas podían agotar el tiempo de ejecución
  (error 500) en bases de datos grandes. Se filtra por rango de código NUTS (usa índice) en lugar de
  `LIKE` de prefijo, se añade un índice de patrón sobre `nuts` (PostgreSQL) y se evita el cálculo más
  costoso (anomalías) en esta vista. La radiografía se cachea durante un mes.

## [1.2.0] - 2026-06-14

### Añadido

- **Radiografía por provincia** (`/radiografia`): nueva sección pública de transparencia que muestra, por
  provincia, el importe de contratación pública adjudicada, el **gasto por habitante** (cruce con el padrón
  del INE), los principales adjudicatarios y organismos, y la evolución anual. Pensada para consulta
  ciudadana y prensa local. Incluye los contratos con código territorial (NUTS) a nivel provincial.

## [1.1.1] - 2026-06-14

### Corregido

- **Dominio de marca en los informes PDF**: el pie/cabecera de los informes (CCAA y anual) mostraba un
  dominio incorrecto; ahora usa el dominio del proyecto (contratacionabierta.com).

### Añadido

- **Config `contratacion.sitio`** (`nombre`, `dominio`, `url`, configurables por variables de entorno
  `SITIO_NOMBRE` / `SITIO_DOMINIO` / `SITIO_URL`): para autoalojadores que quieran personalizar la marca.

## [1.1.0] - 2026-06-14

### Calidad de datos y normalización

- **`data:fix-quality`**: ahora normaliza a NULL las fechas fuera de rango (año < 1900 o futuro
  imposible) en **todas** las columnas de fecha, no solo en la de adjudicación. El límite superior
  es dinámico (año actual + 1); `fecha_limite` admite hasta 2100 (plazos legítimamente futuros).
  Añadido al planificador con cadencia mensual.
- **`nif:merge-masked`**: matcher de adjudicatarios enmascarados endurecido. La confirmación de
  nombre exige coincidencia de palabras completas ("apellidos completos") en lugar de un prefijo,
  y la fusión se restringe a CIF de empresa: nunca se fusionan personas físicas (DNI/NIE), UTEs ni
  identificadores anonimizados. Elimina los falsos positivos previos.

### Documentación

- Página "Sobre" del portal: documenta el repositorio público y el autoalojamiento (MIT).

## [1.0.0] - 2026-06-14

### Primera publicación pública (open source, MIT)

- Portal de transparencia de contratación pública de toda España.
- Importación y normalización desde PLACSP (estatal) y fuentes regionales de datos abiertos.
- Búsqueda full-text, mapa interactivo, análisis y gráficas, rankings, grafo de relaciones,
  detección de anomalías, alertas por email/RSS e informes por CCAA con exportación CSV/PDF.
- Stack: Laravel 12, PostgreSQL 17, Livewire 4, Docker (FrankenPHP).
- ~8,2 millones de contratos.

> Nota: esta es la versión pública reducida. Los datos provienen de fuentes públicas oficiales y
> pueden contener errores; consulte siempre las fuentes oficiales (PLACSP y portales de cada CCAA).
