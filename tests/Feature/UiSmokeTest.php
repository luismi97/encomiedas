<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verifica que cada pantalla y su layout rendericen sin romperse. Los tests de
 * Livewire solo montan el componente, no el layout, asi que un fallo en
 * <x-icon> o en el sidebar no aparecia en ningun lado.
 */
class UiSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    public static function rutas(): array
    {
        return [
            'dashboard'     => ['dashboard'],
            'facturas'      => ['invoices.index'],
            'nueva factura' => ['invoices.create'],
            'hacienda'      => ['hacienda.pending'],
            'sucursales'    => ['branches.index'],
            'impuestos'     => ['taxes.index'],
            'usuarios'      => ['users.index'],
            'actividad'     => ['activity-logs.index'],
            'empresa'       => ['settings.company'],
        ];
    }

    #[DataProvider('rutas')]
    public function test_la_pantalla_renderiza(string $routeName): void
    {
        $response = $this->actingAs($this->admin())->get(route($routeName));

        $response->assertOk();
        // El sidebar usa iconos SVG inline; si el componente falla, no hay <svg>.
        $response->assertSee('<svg', false);
    }

    public function test_el_login_renderiza(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('<svg', false);
    }

    public function test_no_quedan_emojis_en_la_interfaz(): void
    {
        $html = $this->actingAs($this->admin())->get(route('dashboard'))->getContent();

        $this->assertSame(0, preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $html),
            'Quedaron emojis renderizados en la interfaz.');
    }
}
