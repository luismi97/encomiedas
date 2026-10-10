<?php

namespace Tests\Feature\Reportes;

use App\Livewire\Reportes\ReportePanel;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cierres de caja: el filtro por cajero y el detalle de cada turno.
 */
class CierresDeCajaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private User $admin;
    private User $ana;
    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->ana = User::create(['name' => 'Ana Mora', 'username' => 'ana', 'email' => 'ana@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->sj->id]);
        $this->luis = User::create(['name' => 'Luis Solano', 'username' => 'luis', 'email' => 'luis@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->sj->id]);
    }

    private function turnoCerrado(User $cajero, float $fondo, ?User $cierra = null): CashSession
    {
        $caja = app(CajaService::class);
        $turno = $caja->abrir($this->sj->cashRegisters()->firstOrFail(), $cajero, $fondo);
        $caja->registrarMovimiento($turno->fresh(), CashMovement::TYPE_OUT, 1500, 'compra de cinta', $cajero);

        return $caja->cerrar($turno->fresh(), $cierra ?? $cajero, []);
    }

    private function panel()
    {
        return Livewire::actingAs($this->admin)->test(ReportePanel::class)->set('reporte', 'caja');
    }

    public function test_filtra_por_quien_abrio_el_turno(): void
    {
        $this->turnoCerrado($this->ana, 11111);
        // Luis se fue sin cerrar y lo cerró el administrador: sigue siendo su turno.
        $this->turnoCerrado($this->luis, 22222, $this->admin);

        $this->panel()
            ->assertSee('Ana Mora')
            ->assertSee('Luis Solano')
            ->assertSee('Cerró Admin')
            ->set('cajeroId', $this->luis->id)
            ->assertSee('20,722.00') // esperado: fondo menos la salida
            ->assertDontSee('9,611.00')
            ->set('cajeroId', $this->admin->id)
            ->assertSee('No hay cierres de caja');
    }

    public function test_cada_fila_lleva_al_detalle_del_turno(): void
    {
        $turno = $this->turnoCerrado($this->ana, 10000);

        $this->panel()
            ->assertSee(route('reportes.index', ['reporte' => 'caja', 'turno' => $turno->id]))
            ->call('verTurno', $turno->id)
            ->assertSee("Turno #{$turno->id}")
            ->assertSee('compra de cinta')
            ->assertSee('Faltante')
            ->assertSee(route('caja.pdf', $turno), false)
            ->set('reporte', 'estados')
            ->assertSet('turno', null);
    }

    public function test_el_link_abre_el_detalle_directo(): void
    {
        $turno = $this->turnoCerrado($this->ana, 10000);

        $this->actingAs($this->admin)
            ->get(route('reportes.index', ['reporte' => 'caja', 'turno' => $turno->id]))
            ->assertOk()
            ->assertSee("Turno #{$turno->id}")
            ->assertSee('compra de cinta');
    }
}
