<?php

namespace Tests\Feature\Ui;

use App\Livewire\Branches\BranchIndex;
use App\Livewire\CashRegisters\CashRegisterIndex;
use App\Livewire\Users\UserIndex;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tocar «Editar» lleva al formulario.
 *
 * El formulario se dibuja arriba del listado, y quien editaba una fila de abajo
 * no lo veía aparecer: parecía que el botón no hacía nada. Ahora el formulario
 * se lleva solo a la vista (mostrarFormulario, en el layout) cada vez que se
 * monta, y su wire:key cambia con el registro para que editar otra fila con el
 * formulario ya abierto lo vuelva a montar en vez de solo cambiarle los datos.
 */
class FormularioALaVistaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Branch $sj;
    private Branch $lim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        $this->admin = User::create([
            'name' => 'Ana Administradora', 'username' => 'ana', 'email' => 'ana@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    public function test_cada_edicion_de_usuario_monta_su_propio_formulario(): void
    {
        $otro = User::create([
            'name' => 'Yolanda Cajera', 'username' => 'yolanda', 'email' => 'yolanda@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->sj->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(UserIndex::class)
            ->call('create')
            ->assertSeeHtml('wire:key="formulario-nuevo"')
            ->assertSeeHtml('x-init="mostrarFormulario($el)"')
            ->call('edit', $this->admin->id)
            ->assertSeeHtml('wire:key="formulario-' . $this->admin->id . '"')
            ->call('edit', $otro->id)
            ->assertSeeHtml('wire:key="formulario-' . $otro->id . '"');
    }

    public function test_sedes_y_cajas_tambien(): void
    {
        Livewire::actingAs($this->admin)
            ->test(BranchIndex::class)
            ->call('edit', $this->lim->id)
            ->assertSeeHtml('wire:key="formulario-' . $this->lim->id . '"')
            ->assertSeeHtml('x-init="mostrarFormulario($el)"');

        $caja = $this->sj->cashRegisters()->firstOrCreate(['name' => 'Caja principal'], ['is_active' => true]);

        Livewire::actingAs($this->admin)
            ->test(CashRegisterIndex::class)
            ->call('edit', $caja->id)
            ->assertSeeHtml('wire:key="formulario-' . $caja->id . '"')
            ->assertSeeHtml('x-init="mostrarFormulario($el)"');
    }

    public function test_el_layout_define_la_funcion(): void
    {
        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('window.mostrarFormulario', false);
    }
}
