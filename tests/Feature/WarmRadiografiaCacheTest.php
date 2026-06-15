<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Provincia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WarmRadiografiaCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_warm_precalienta_la_cache_de_provincias(): void
    {
        $provincia = Provincia::whereNotNull('nuts')->orderBy('nombre')->firstOrFail();
        Cache::forget("radiografia:{$provincia->id}:all");

        $this->artisan('radiografia:warm')->assertExitCode(0);

        $this->assertTrue(Cache::has("radiografia:{$provincia->id}:all"));
        $data = Cache::get("radiografia:{$provincia->id}:all");
        $this->assertArrayHasKey('kpis', $data);
    }
}
