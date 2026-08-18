<?php

namespace Tests\Feature\Caja;

use App\Livewire\Caja\CajaPanel;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Denomination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Las cajas y las denominaciones solo nacían en CajaSeeder, al que
 * DatabaseSeeder nunca llamaba: después de un `migrate` + `db:seed` la pantalla
 * de caja mostraba un selector vacío y el botón respondía «elegí una caja»
 * señalando a una lista sin nada que elegir. Y no había ninguna pantalla para
 * crear la caja que faltaba.
 */
class CajaDisponibleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function sede(string $nombre = 'San José', string $codigo = '001'): Branch
    {
        return Branch::create([
            'name' => $nombre, 'prefix' => strtoupper(substr($nombre, 0, 2)),
            'sucursal_code' => $codigo, 'terminal_code' => '00001', 'is_active' => true,
        ]);
    }

    /** El arreglo de raíz: una sede no puede quedar sin caja. */
    public function test_cada_sede_nace_con_su_caja_principal(): void
    {
        $sede = $this->sede();

        $this->assertSame(1, $sede->cashRegisters()->count());
        $this->assertSame(Branch::CAJA_PRINCIPAL, $sede->cashRegisters()->first()->name);
        $this->assertTrue($sede->cashRegisters()->first()->is_active);
    }

    public function test_el_selector_trae_la_caja_de_la_sede(): void
    {
        $this->sede();

        Livewire::actingAs($this->admin())
            ->test(CajaPanel::class)
            ->assertSet('registerId', CashRegister::firstOrFail()->id)
            ->assertSee(Branch::CAJA_PRINCIPAL);
    }

    /** El bug reportado, de punta a punta: abrir el turno sin tocar nada más. */
    public function test_se_puede_abrir_el_turno_recien_creada_la_sede(): void
    {
        $this->sede();

        Livewire::actingAs($this->admin())
            ->test(CajaPanel::class)
            ->set('openingFloat', 25000)
            ->call('abrir')
            ->assertSet('feedbackType', 'success');

        $sesion = CashSession::firstOrFail();
        $this->assertSame(CashSession::STATUS_OPEN, $sesion->status);
        $this->assertEquals(25000, $sesion->opening_float);
    }

    /** Un cajero sin sede asignada tampoco puede quedarse sin caja que elegir. */
    public function test_un_cajero_sin_sede_igual_ve_una_caja(): void
    {
        $this->sede();

        $cajero = User::create([
            'name' => 'Yolanda', 'username' => 'yolanda', 'email' => 'y@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
        ]);

        Livewire::actingAs($cajero)
            ->test(CajaPanel::class)
            ->assertNotSet('registerId', null);
    }

    /** Sin cajas la pantalla lo dice, en vez de pedir que se elija de una lista vacía. */
    public function test_sin_cajas_la_pantalla_explica_que_hacer(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CajaPanel::class)
            ->assertSee('Todavía no hay ninguna caja')
            ->assertDontSee('Abrir turno');
    }

    public function test_el_administrador_puede_crear_las_cajas_faltantes(): void
    {
        $sede = $this->sede();
        CashRegister::query()->delete(); // Simula una base anterior al arreglo.

        Livewire::actingAs($this->admin())
            ->test(CajaPanel::class)
            ->call('crearCajasFaltantes')
            ->assertSet('feedbackType', 'success')
            ->assertSet('registerId', fn ($id) => $id !== null);

        $this->assertSame(1, $sede->cashRegisters()->count());
    }

    public function test_crear_cajas_no_duplica_las_que_ya_existen(): void
    {
        $this->sede();
        $this->sede('Limón', '002');

        Livewire::actingAs($this->admin())
            ->test(CajaPanel::class)
            ->call('crearCajasFaltantes')
            ->assertSet('feedbackType', 'error');

        $this->assertSame(2, CashRegister::count());
    }

    public function test_un_cajero_no_puede_crear_cajas(): void
    {
        $this->sede();
        CashRegister::query()->delete();

        $cajero = User::create([
            'name' => 'Yolanda', 'username' => 'yolanda', 'email' => 'y@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
        ]);

        Livewire::actingAs($cajero)
            ->test(CajaPanel::class)
            ->call('crearCajasFaltantes')
            ->assertSet('feedbackType', 'error');

        $this->assertSame(0, CashRegister::count());
    }

    /** Sin denominaciones no hay arqueo: el turno se abre pero no se cierra. */
    public function test_el_seeder_deja_las_denominaciones_del_arqueo(): void
    {
        $this->seed(\Database\Seeders\CajaSeeder::class);

        $this->assertGreaterThan(0, Denomination::active()->count());
        $this->assertTrue(Denomination::where('value', 20000)->exists());
        $this->assertTrue(Denomination::where('value', 5)->exists());
    }
}
