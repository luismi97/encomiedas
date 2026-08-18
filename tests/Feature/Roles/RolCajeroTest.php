<?php

namespace Tests\Feature\Roles;

use App\Livewire\Users\UserIndex;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El cajero opera (recibe, cobra, imprime) sobre SU sede. No configura el
 * sistema ni toca lo fiscal.
 */
class RolCajeroTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
    }

    private function cajero(): User
    {
        return User::create([
            'name' => 'Yolanda Campos', 'username' => 'cajera', 'email' => 'cajera@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
            'branch_id' => $this->sede->id,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    public function test_el_cajero_entra_a_la_operacion_diaria(): void
    {
        $cajero = $this->cajero();

        foreach (['caja.index', 'dispatches.index', 'invoices.create', 'customers.index', 'credito.index'] as $ruta) {
            $this->actingAs($cajero)->get(route($ruta))->assertOk();
        }
    }

    public function test_el_cajero_no_entra_a_la_configuracion(): void
    {
        $cajero = $this->cajero();

        foreach (['branches.index', 'rates.index', 'taxes.index', 'users.index', 'settings.company', 'hacienda.pending'] as $ruta) {
            $this->actingAs($cajero)->get(route($ruta))->assertForbidden();
        }
    }

    public function test_el_administrador_entra_a_todo(): void
    {
        $admin = $this->admin();

        foreach (['caja.index', 'dispatches.index', 'branches.index', 'users.index', 'settings.company', 'credito.index'] as $ruta) {
            $this->actingAs($admin)->get(route($ruta))->assertOk();
        }
    }

    public function test_los_permisos_se_leen_por_capacidad_y_no_por_rol(): void
    {
        $cajero = $this->cajero();
        $admin = $this->admin();

        $this->assertTrue($cajero->puedeOperarCaja());
        $this->assertFalse($cajero->puedeConfigurar());
        $this->assertTrue($cajero->limitadoASuSede());

        $this->assertTrue($admin->puedeOperarCaja());
        $this->assertTrue($admin->puedeConfigurar());
        $this->assertFalse($admin->limitadoASuSede());
    }

    /** Sin sede no habría contra cuál validar: operaría la caja de cualquiera. */
    public function test_un_cajero_necesita_sede_asignada(): void
    {
        Livewire::actingAs($this->admin())
            ->test(UserIndex::class)
            ->call('create')
            ->set('name', 'Nuevo cajero')
            ->set('email', 'nuevo@t.test')
            ->set('username', 'nuevocajero')
            ->set('password', 'secreto123')
            ->set('role', User::ROLE_CAJERO)
            ->set('branch_id', null)
            ->call('save')
            ->assertHasErrors('branch_id')
            ->assertSee('necesita sede asignada');
    }

    public function test_un_cajero_con_sede_se_guarda(): void
    {
        Livewire::actingAs($this->admin())
            ->test(UserIndex::class)
            ->call('create')
            ->set('name', 'Nuevo cajero')
            ->set('email', 'nuevo@t.test')
            ->set('username', 'nuevocajero')
            ->set('password', 'secreto123')
            ->set('role', User::ROLE_CAJERO)
            ->set('branch_id', $this->sede->id)
            ->call('save')
            ->assertHasNoErrors();

        $creado = User::where('email', 'nuevo@t.test')->firstOrFail();

        $this->assertSame(User::ROLE_CAJERO, $creado->role);
        $this->assertSame($this->sede->id, $creado->branch_id);
        $this->assertSame('Cajero', $creado->roleLabel());
    }

    /** El administrador sí puede quedar sin sede: no está limitado a ninguna. */
    public function test_un_administrador_no_necesita_sede(): void
    {
        Livewire::actingAs($this->admin())
            ->test(UserIndex::class)
            ->call('create')
            ->set('name', 'Otro admin')
            ->set('email', 'otro@t.test')
            ->set('username', 'otroadmin')
            ->set('password', 'secreto123')
            ->set('role', User::ROLE_ADMIN)
            ->set('branch_id', null)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_el_menu_del_cajero_no_muestra_la_configuracion(): void
    {
        $html = $this->actingAs($this->cajero())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('Caja', $html);
        $this->assertStringContainsString('Cierres de envío', $html);
        $this->assertStringNotContainsString('Configuración de la empresa', $html);
        $this->assertStringNotContainsString('Actividad de usuarios', $html);
    }

    public function test_la_barra_superior_muestra_el_rol_y_la_sede(): void
    {
        $html = $this->actingAs($this->cajero())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('Cajero', $html);
        $this->assertStringContainsString('San José', $html);
    }
}
