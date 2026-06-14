# Changelog

Todos los cambios relevantes de la versión pública de este proyecto se documentan aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/) y versionado semántico.

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
