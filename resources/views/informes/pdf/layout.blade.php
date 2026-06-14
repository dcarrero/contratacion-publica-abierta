<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.4; }
        .header { background: #991b1b; color: white; padding: 15px 20px; margin-bottom: 20px; }
        .header h1 { font-size: 16px; margin: 0; }
        .header .subtitle { font-size: 10px; opacity: 0.8; margin-top: 3px; }
        .content { padding: 0 20px; }
        h2 { font-size: 13px; color: #991b1b; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin: 15px 0 8px 0; }
        .kpi-grid { width: 100%; margin-bottom: 15px; }
        .kpi-grid td { width: 25%; padding: 8px; text-align: center; border: 1px solid #e5e5e5; }
        .kpi-value { font-size: 16px; font-weight: bold; color: #1a1a1a; }
        .kpi-label { font-size: 8px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
        table.data { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 10px; }
        table.data th { background: #f3f4f6; color: #374151; text-transform: uppercase; font-size: 8px; letter-spacing: 0.5px; padding: 5px 6px; text-align: left; border-bottom: 2px solid #ddd; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #eee; }
        table.data tr:nth-child(even) { background: #fafafa; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-muted { color: #888; font-size: 9px; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; background: #f9fafb; border-top: 1px solid #ddd; padding: 8px 20px; font-size: 8px; color: #888; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <div class="header">
        <h1>@yield('title')</h1>
        <div class="subtitle">{{ config('contratacion.sitio.nombre') }} &mdash; {{ config('contratacion.sitio.dominio') }}</div>
    </div>

    <div class="content">
        @yield('content')
    </div>

    <div class="footer">
        Fuente: Plataforma de Contratación del Sector Público (PLACSP) y portales regionales de datos abiertos &bull;
        Generado: {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
