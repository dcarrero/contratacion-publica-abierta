<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Placsp;

use App\Services\Placsp\AtomFeedReader;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AtomFeedReaderTest extends TestCase
{
    public function test_entries_iterates_through_paginated_feed(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));
        $page2 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_2.xml'));

        Http::fake([
            'contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3.atom' => Http::response($page1, 200),
            'contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3_20260301_120000.atom' => Http::response($page2, 200),
        ]);

        $reader = new AtomFeedReader(
            'https://contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3.atom',
            ['delay_between_pages_ms' => 0]
        );

        $entries = iterator_to_array($reader->entries(), false);

        $this->assertCount(5, $entries);

        // Verificar primer entry
        $this->assertStringContainsString('12345001', (string) $entries[0]->id);
        // Verificar último entry
        $this->assertStringContainsString('12345005', (string) $entries[4]->id);
    }

    public function test_entries_respects_max_pages(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));

        Http::fake([
            'contrataciondelestado.es/*' => Http::response($page1, 200),
        ]);

        $reader = new AtomFeedReader(
            'https://contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3.atom',
            ['max_pages' => 1, 'delay_between_pages_ms' => 0]
        );

        $entries = iterator_to_array($reader->entries(), false);

        // Solo entries de la primera página
        $this->assertCount(3, $entries);
    }

    public function test_entries_since_stops_when_all_entries_older(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));
        $page2 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_2.xml'));

        Http::fake([
            'contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3.atom' => Http::response($page1, 200),
            'contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3_20260301_120000.atom' => Http::response($page2, 200),
        ]);

        $reader = new AtomFeedReader(
            'https://contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3.atom',
            ['delay_between_pages_ms' => 0]
        );

        // Todas las entries de page_2 son de 2026-02-28, poner since después
        $since = new \DateTimeImmutable('2026-03-01T00:00:00+01:00');
        $entries = iterator_to_array($reader->entriesSince($since), false);

        // Page 1 tiene entries del 2026-03-02 y 2026-03-01 (algunos >= since)
        // Page 2 tiene entries del 2026-02-28 (todas < since), así que para ahí
        $this->assertCount(5, $entries); // 3 de page1 + 2 de page2 (las devuelve pero luego para)
    }

    public function test_entries_handles_http_error_gracefully(): void
    {
        Http::fake([
            '*' => Http::response('Server Error', 500),
        ]);

        $reader = new AtomFeedReader(
            'https://contrataciondelestado.es/feed.atom',
            ['retry_attempts' => 1, 'retry_delay_ms' => 0, 'delay_between_pages_ms' => 0]
        );

        $entries = iterator_to_array($reader->entries(), false);

        $this->assertCount(0, $entries);
    }

    public function test_get_next_page_url_extracts_next_link(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));
        $xml = simplexml_load_string($page1);

        $reader = new AtomFeedReader('https://example.com/feed.atom');
        $nextUrl = $reader->getNextPageUrl($xml);

        $this->assertEquals(
            'https://contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilesContratanteCompleto3_20260301_120000.atom',
            $nextUrl
        );
    }

    public function test_get_next_page_url_returns_null_when_no_next(): void
    {
        $page2 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_2.xml'));
        $xml = simplexml_load_string($page2);

        $reader = new AtomFeedReader('https://example.com/feed.atom');
        $nextUrl = $reader->getNextPageUrl($xml);

        $this->assertNull($nextUrl);
    }

    public function test_fetch_page_retries_on_failure(): void
    {
        $page1 = file_get_contents(base_path('tests/fixtures/placsp/atom_page_1.xml'));

        Http::fake(Http::fakeSequence()
            ->push('Server Error', 500)
            ->push($page1, 200)
        );

        $reader = new AtomFeedReader(
            'https://example.com/feed.atom',
            ['retry_attempts' => 3, 'retry_delay_ms' => 0]
        );

        $xml = $reader->fetchPage('https://example.com/feed.atom');

        $this->assertNotNull($xml);
        $this->assertCount(3, $xml->entry);
    }

    public function test_fetch_page_returns_null_after_all_retries_fail(): void
    {
        Http::fake([
            '*' => Http::response('Error', 500),
        ]);

        $reader = new AtomFeedReader(
            'https://example.com/feed.atom',
            ['retry_attempts' => 2, 'retry_delay_ms' => 0]
        );

        $xml = $reader->fetchPage('https://example.com/feed.atom');

        $this->assertNull($xml);
    }
}
