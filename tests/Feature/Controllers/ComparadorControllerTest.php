<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Provincia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComparadorControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withoutVite();
    }

    public function test_index_devuelve_200(): void
    {
        $this->get('/comparar')->assertStatus(200);
    }

    public function test_show_dos_provincias_devuelve_200(): void
    {
        [$a, $b] = Provincia::whereNotNull('nuts')->orderBy('nombre')->take(2)->get();

        $this->get('/comparar/'.Str::slug($a->nombre).'/'.Str::slug($b->nombre))
            ->assertStatus(200)
            ->assertSee($a->nombre)
            ->assertSee($b->nombre);
    }

    public function test_show_provincia_inexistente_devuelve_404(): void
    {
        $a = Provincia::whereNotNull('nuts')->orderBy('nombre')->first();

        $this->get('/comparar/'.Str::slug($a->nombre).'/provincia-que-no-existe')->assertStatus(404);
    }
}
