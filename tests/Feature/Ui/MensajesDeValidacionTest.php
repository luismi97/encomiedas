<?php

namespace Tests\Feature\Ui;

use App\Livewire\Users\UserIndex;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La aplicación corre en español y no había archivos de idioma: toda regla sin
 * mensaje propio en el componente salía como la clave cruda. Un usuario con un
 * punto en el nombre de usuario veía «validation.alpha_dash».
 */
class MensajesDeValidacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_usuario_con_punto_explica_el_error_en_espanol(): void
    {
        $sede = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $admin = User::create([
            'name' => 'Ana Administradora', 'username' => 'ana', 'email' => 'ana@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(UserIndex::class)
            ->call('create')
            ->set('name', 'María Rodríguez')
            ->set('username', 'maria.r')
            ->set('email', 'maria@t.test')
            ->set('password', 'clave-segura-123')
            ->set('role', User::ROLE_CAJERO)
            ->set('branch_id', $sede->id)
            ->call('save')
            ->assertHasErrors(['username' => 'alpha_dash'])
            ->assertSee('sin espacios ni puntos')
            ->assertDontSee('validation.alpha_dash');
    }

    public function test_ninguna_regla_comun_queda_sin_traducir(): void
    {
        foreach (['required', 'alpha_dash', 'email', 'unique', 'numeric', 'integer', 'date', 'in', 'exists'] as $regla) {
            $this->assertStringNotContainsString('validation.', __("validation.$regla"), "Falta traducir «{$regla}».");
        }

        foreach (['max', 'min', 'between', 'size'] as $regla) {
            $this->assertStringNotContainsString('validation.', __("validation.$regla.string"), "Falta traducir «{$regla}».");
        }

        $this->assertSame('Siguiente &raquo;', __('pagination.next'));
    }
}
