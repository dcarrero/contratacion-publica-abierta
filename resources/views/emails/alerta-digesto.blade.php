<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="border-bottom: 3px solid #dc2626; padding-bottom: 16px; margin-bottom: 24px;">
        <h1 style="font-size: 20px; margin: 0; color: #dc2626;">Contratación Abierta</h1>
    </div>

    <h2 style="font-size: 18px; color: #111;">{{ $contratos->count() }} nuevos contratos</h2>

    <p>Novedades en tu seguimiento de <strong>{{ $nombre }}</strong>:</p>

    <table style="width: 100%; border-collapse: collapse; font-size: 14px; margin: 16px 0;">
        <thead>
            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                <th style="padding: 8px; text-align: left;">Fecha</th>
                <th style="padding: 8px; text-align: left;">Objeto</th>
                <th style="padding: 8px; text-align: right;">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach($contratos->take(20) as $contrato)
            <tr style="border-bottom: 1px solid #e5e7eb;">
                <td style="padding: 8px; white-space: nowrap; color: #6b7280; font-size: 13px;">
                    {{ $contrato->fecha_publicacion?->format('d/m/Y') ?? '—' }}
                </td>
                <td style="padding: 8px;">
                    <a href="{{ url('/contratos/' . $contrato->placsp_id) }}" style="color: #dc2626; text-decoration: none;">
                        {{ Str::limit($contrato->objeto, 60) }}
                    </a>
                </td>
                <td style="padding: 8px; text-align: right; white-space: nowrap; font-weight: 500;">
                    @if($contrato->importe_adjudicacion)
                        {{ number_format((float) $contrato->importe_adjudicacion, 0, ',', '.') }} €
                    @else
                        —
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @if($contratos->count() > 20)
    <p style="font-size: 13px; color: #6b7280;">
        ... y {{ $contratos->count() - 20 }} contratos más.
    </p>
    @endif

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">

    <p style="font-size: 12px; color: #9ca3af;">
        <a href="{{ $cancelarUrl }}" style="color: #9ca3af;">Cancelar esta suscripción</a>
        &nbsp;|&nbsp;
        Contratación Abierta — Portal de transparencia en contratación pública de España.
    </p>
</body>
</html>
