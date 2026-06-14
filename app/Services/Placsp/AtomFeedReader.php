<?php

declare(strict_types=1);

namespace App\Services\Placsp;

use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class AtomFeedReader
{
    private string $feedUrl;

    private int $maxPages;

    private int $timeoutSeconds;

    private int $retryAttempts;

    private int $retryDelayMs;

    private int $delayBetweenPagesMs;

    public function __construct(string $feedUrl, array $options = [])
    {
        $this->feedUrl = $feedUrl;

        $config = config('contratacion.placsp_sync', []);

        $this->maxPages = $options['max_pages'] ?? $config['max_pages'] ?? 100;
        $this->timeoutSeconds = $options['timeout_seconds'] ?? $config['timeout_seconds'] ?? 30;
        $this->retryAttempts = $options['retry_attempts'] ?? $config['retry_attempts'] ?? 3;
        $this->retryDelayMs = $options['retry_delay_ms'] ?? $config['retry_delay_ms'] ?? 1000;
        $this->delayBetweenPagesMs = $options['delay_between_pages_ms'] ?? $config['delay_between_pages_ms'] ?? 1000;
    }

    /**
     * Itera todas las entries del feed, siguiendo paginación.
     *
     * @return Generator<int, SimpleXMLElement>
     */
    public function entries(): Generator
    {
        $url = $this->feedUrl;
        $page = 0;

        while ($url !== null && $page < $this->maxPages) {
            $xml = $this->fetchPage($url);

            if ($xml === null) {
                Log::warning("AtomFeedReader: no se pudo obtener página {$page}", ['url' => $url]);
                break;
            }

            foreach ($xml->entry as $entry) {
                yield $entry;
            }

            $url = $this->getNextPageUrl($xml);
            $page++;

            if ($url !== null && $this->delayBetweenPagesMs > 0) {
                usleep($this->delayBetweenPagesMs * 1000);
            }
        }
    }

    /**
     * Itera entries hasta encontrar una página donde todos los entries
     * son anteriores a $since (feed ordenado por fecha desc).
     *
     * @return Generator<int, SimpleXMLElement>
     */
    public function entriesSince(\DateTimeInterface $since): Generator
    {
        $url = $this->feedUrl;
        $page = 0;

        while ($url !== null && $page < $this->maxPages) {
            $xml = $this->fetchPage($url);

            if ($xml === null) {
                break;
            }

            $allOlderThanSince = true;

            foreach ($xml->entry as $entry) {
                $updatedStr = (string) $entry->updated;

                if ($updatedStr !== '') {
                    try {
                        $entryDate = new \DateTimeImmutable($updatedStr);
                        if ($entryDate >= $since) {
                            $allOlderThanSince = false;
                        }
                    } catch (\Exception) {
                        $allOlderThanSince = false;
                    }
                } else {
                    $allOlderThanSince = false;
                }

                yield $entry;
            }

            if ($allOlderThanSince) {
                break;
            }

            $url = $this->getNextPageUrl($xml);
            $page++;

            if ($url !== null && $this->delayBetweenPagesMs > 0) {
                usleep($this->delayBetweenPagesMs * 1000);
            }
        }
    }

    /**
     * Descarga una página del feed Atom con retry exponencial.
     */
    public function fetchPage(string $url): ?SimpleXMLElement
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
            try {
                $response = Http::timeout($this->timeoutSeconds)
                    ->withHeaders([
                        'User-Agent' => 'ContratacionAbierta/2.0 (transparencia; +https://github.com/dcarrero/contratacion-publica-clm-es)',
                        'Accept' => 'application/atom+xml, application/xml, text/xml',
                    ])
                    ->get($url);

                if (! $response->successful()) {
                    Log::warning("AtomFeedReader: HTTP {$response->status()}", [
                        'url' => $url,
                        'attempt' => $attempt,
                    ]);
                    $lastException = new \RuntimeException("HTTP {$response->status()}");

                    if ($attempt < $this->retryAttempts) {
                        usleep($this->retryDelayMs * 1000 * $attempt);
                    }

                    continue;
                }

                $body = $response->body();

                if (empty($body)) {
                    return null;
                }

                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($body);
                libxml_clear_errors();

                if ($xml === false) {
                    Log::error('AtomFeedReader: XML inválido', ['url' => $url]);

                    return null;
                }

                return $xml;

            } catch (\Exception $e) {
                $lastException = $e;
                Log::warning("AtomFeedReader: excepción en intento {$attempt}", [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $this->retryAttempts) {
                    usleep($this->retryDelayMs * 1000 * $attempt);
                }
            }
        }

        Log::error('AtomFeedReader: agotados reintentos', [
            'url' => $url,
            'error' => $lastException?->getMessage(),
        ]);

        return null;
    }

    /**
     * Extrae la URL de la siguiente página desde <link rel="next">.
     */
    public function getNextPageUrl(SimpleXMLElement $xml): ?string
    {
        foreach ($xml->link as $link) {
            $rel = (string) $link['rel'];
            $href = (string) $link['href'];

            if ($rel === 'next' && $href !== '') {
                return $href;
            }
        }

        return null;
    }
}
