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

    /**
     * El estado visual por defecto del boton tiene que venir del servidor. Si
     * el label es x-text y el spinner x-show sin x-cloak, basta con que el
     * bundle tarde o falle para que el boton quede girando y sin texto.
     *
     * Ojo: no se puede buscar "x-cloak" en el HTML completo porque Livewire
     * auto-inyecta un <style> con la regla [x-cloak].
     */
    public function test_el_login_no_depende_de_js_para_su_estado_inicial(): void
    {
        $html = $this->get(route('login'))->getContent();
        $form = substr($html, strpos($html, '<form'), strpos($html, '</form>') - strpos($html, '<form'));

        foreach (['x-data', 'x-show', 'x-text', 'x-init'] as $directive) {
            $this->assertStringNotContainsString($directive, $form,
                "El formulario de login depende de {$directive} para su estado inicial.");
        }

        $this->assertStringContainsString('>Entrar</span>', $form);
        $this->assertStringContainsString('data-login-spinner hidden', $form);
    }

    /**
     * En Tailwind, border-gray-300 define solo el COLOR. Sin la utilidad
     * "border" el preflight deja border-width en 0 y el campo se ve flotando.
     * Este proyecto no tiene el plugin @tailwindcss/forms que lo restauraria.
     */
    public function test_los_campos_del_login_declaran_ancho_de_borde(): void
    {
        $html = $this->get(route('login'))->getContent();

        preg_match_all('/<input[^>]*>/', $html, $matches);
        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $input) {
            if (str_contains($input, 'type="hidden"')) {
                continue;
            }

            preg_match('/class="([^"]*)"/', $input, $class);
            $classes = $class[1] ?? '';

            $this->assertTrue(
                str_contains($classes, 'input') || str_contains($classes, 'checkbox'),
                "Campo del login sin clase de borde del sistema: {$classes}"
            );
            $this->assertDoesNotMatchRegularExpression('/(?<!-)\bborder-gray-\d+/', $classes,
                "El login declara un color de borde suelto, sin ancho: {$classes}");
        }
    }

    public function test_no_quedan_emojis_en_la_interfaz(): void
    {
        $html = $this->actingAs($this->admin())->get(route('dashboard'))->getContent();

        $this->assertSame(0, preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $html),
            'Quedaron emojis renderizados en la interfaz.');
    }
}
