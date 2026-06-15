# Changelog

Todos los cambios relevantes de la versión pública de este proyecto se documentan aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/) y versionado semántico.

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
