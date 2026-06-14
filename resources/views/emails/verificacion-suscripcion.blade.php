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

    <h2 style="font-size: 18px; color: #111;">Confirma tu suscripción</h2>

    <p>Has solicitado recibir alertas sobre:</p>

    <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin: 16px 0;">
        <strong>{{ ucfirst($tipo) }}:</strong> {{ $nombre }}
    </div>

    <p>Para activar tu suscripción, haz clic en el siguiente enlace:</p>

    <p style="text-align: center; margin: 24px 0;">
        <a href="{{ $url }}" style="display: inline-block; background: #dc2626; color: #fff; padding: 12px 32px; border-radius: 6px; text-decoration: none; font-weight: 600;">
            Confirmar suscripción
        </a>
    </p>

    <p style="font-size: 13px; color: #6b7280;">
        Si no solicitaste esta suscripción, ignora este email. El enlace es válido de forma indefinida.
    </p>

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">

    <p style="font-size: 12px; color: #9ca3af;">
        Contratación Abierta — Portal de transparencia en contratación pública de España.
    </p>
</body>
</html>
