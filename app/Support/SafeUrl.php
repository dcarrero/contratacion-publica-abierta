<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validación de URLs que llegan de fuentes externas (feeds, CKAN, JSON regionales).
 *
 * Dos usos:
 *  - Enlaces en vistas (href): solo http/https, para que un `javascript:` importado
 *    no acabe ejecutándose al hacer clic.
 *  - Descargas en los comandos de sincronización: solo https y solo hacia los hosts
 *    oficiales esperados, también tras redirecciones, para que una fuente manipulada
 *    no pueda dirigir al servidor contra la red interna (SSRF).
 */
final class SafeUrl
{
    /** Máximo de redirecciones que siguen las descargas. */
    public const MAX_REDIRECTS = 5;

    /**
     * Devuelve la URL si es http(s) bien formada; null en otro caso. Para href.
     */
    public static function forLink(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
            return null;
        }

        return $url;
    }

    /**
     * ¿Es una URL https, sin credenciales ni puerto raro, cuyo host es uno de los
     * dominios permitidos o un subdominio suyo?
     *
     * @param  list<string>  $allowedDomains  p. ej. ['euskadi.eus', 'datos.madrid.es']
     */
    public static function isAllowedDownload(string $url, array $allowedDomains): bool
    {
        $parts = parse_url(trim($url));

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'] ?? '', '.'));

        // Una IP literal nunca es uno de los dominios oficiales.
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach ($allowedDomains as $domain) {
            $domain = strtolower($domain);

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Opciones de cURL que limitan la descarga y sus redirecciones a https.
     *
     * @return array<int, mixed>
     */
    public static function curlOptions(): array
    {
        return [
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
        ];
    }

    /**
     * Opciones de Guzzle (Http::withOptions) equivalentes.
     *
     * @return array<string, mixed>
     */
    public static function guzzleOptions(): array
    {
        return [
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                'strict' => true,
                'protocols' => ['https'],
            ],
        ];
    }
}
