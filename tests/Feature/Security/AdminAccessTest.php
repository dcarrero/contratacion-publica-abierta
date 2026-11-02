<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Auditoría 2026-11 (AUTH-01, AUTH-02, AUTH-09): estar autenticado no basta para el
 * panel; hace falta is_admin, y la lista de IP falla cerrada.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['contratacion.admin.allowed_ips' => ['0.0.0.0/0', '::/0']]);
    }

    public function test_un_usuario_registrado_sin_rol_no_entra_en_el_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->post('/admin/comandos/run', ['command' => 'cache:clear'])->assertForbidden();
    }

    public function test_un_admin_entra_en_el_panel(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_el_login_de_admin_rechaza_cuentas_sin_rol_aunque_la_contrasena_sea_buena(): void
    {
        User::factory()->create(['email' => 'cliente@example.com', 'password' => Hash::make('una-clave-larga-123')]);

        $this->post('/admin/login', ['email' => 'cliente@example.com', 'password' => 'una-clave-larga-123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_el_login_de_admin_acepta_al_admin(): void
    {
        User::factory()->create([
            'email' => 'jefa@example.com',
            'password' => Hash::make('una-clave-larga-123'),
            'is_admin' => true,
        ]);

        $this->post('/admin/login', ['email' => 'jefa@example.com', 'password' => 'una-clave-larga-123'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_el_login_de_admin_bloquea_la_cuenta_tras_varios_fallos_aunque_cambie_la_ip(): void
    {
        User::factory()->create([
            'email' => 'jefa@example.com',
            'password' => Hash::make('una-clave-larga-123'),
            'is_admin' => true,
        ]);

        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->post('/admin/login', ['email' => 'jefa@example.com', 'password' => 'mala']);
        }

        // Contraseña correcta desde otra IP: sigue bloqueada.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post('/admin/login', ['email' => 'jefa@example.com', 'password' => 'una-clave-larga-123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_la_lista_de_ip_vacia_no_deja_entrar_a_nadie(): void
    {
        config(['contratacion.admin.allowed_ips' => []]);

        $this->get('/admin/login')->assertForbidden();
    }

    public function test_la_lista_de_ip_admite_rangos_cidr(): void
    {
        config(['contratacion.admin.allowed_ips' => ['192.168.1.0/24']]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])->get('/admin/login')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.2.40'])->get('/admin/login')->assertForbidden();
    }

    public function test_el_seeder_recupera_la_direccion_de_admin_si_otro_la_registro_antes(): void
    {
        config([
            'contratacion.admin.email' => 'admin@example.com',
            'contratacion.admin.default_password' => 'clave-del-operador-2026',
        ]);

        $intruso = User::factory()->create([
            'email' => 'Admin@Example.com',
            'password' => Hash::make('clave-del-intruso'),
            'remember_token' => 'token-del-intruso',
        ]);

        $this->seed(AdminUserSeeder::class);

        $intruso->refresh();
        $this->assertTrue($intruso->isAdmin());
        $this->assertTrue(Hash::check('clave-del-operador-2026', $intruso->password));
        $this->assertNotSame('token-del-intruso', $intruso->remember_token);
        $this->assertSame(1, User::count());
    }
}
