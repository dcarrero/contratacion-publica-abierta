<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Str;

class NormalizeName
{
    /**
     * Formas societarias a normalizar.
     */
    private const SOCIETY_FORMS = [
        '/\bS\.?\s*L\.?\s*U\.?\b/i' => 'SLU',
        '/\bS\.?\s*L\.?\s*L\.?\b/i' => 'SLL',
        '/\bS\.?\s*L\.?\b/i' => 'SL',
        '/\bS\.?\s*A\.?\s*U\.?\b/i' => 'SAU',
        '/\bS\.?\s*A\.?\b/i' => 'SA',
        '/\bS\.?\s*C\.?\s*O\.?\s*O\.?\s*P\.?\b/i' => 'SCOOP',
        '/\bS\.?\s*C\.?\b/i' => 'SC',
        '/\bS\.?\s*L\.?\s*P\.?\b/i' => 'SLP',
        '/\bC\.?\s*B\.?\b/i' => 'CB',
        '/\bU\.?\s*T\.?\s*E\.?\b/i' => 'UTE',
    ];

    public function __invoke(string $name): string
    {
        // Convertir a ASCII (quita tildes)
        $normalized = Str::ascii($name);

        // Uppercase
        $normalized = mb_strtoupper($normalized);

        // Normalizar formas societarias
        foreach (self::SOCIETY_FORMS as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized) ?? $normalized;
        }

        // Eliminar puntuación excepto letras, números y espacios
        $normalized = preg_replace('/[^A-Z0-9\s]/', ' ', $normalized) ?? $normalized;

        // Colapsar espacios múltiples
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
