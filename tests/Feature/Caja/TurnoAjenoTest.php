<?php

namespace Tests\Feature\Caja;

use App\Livewire\Caja\CajaPanel;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Denomination;
use App\Models\User;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El turno de otro cajero no se toca.
 *
 * Un arqueo responde por quien lo abrió. Si un compañero le registra salidas o
 * se lo cierra, el faltante aparece a nombre de quien no manejó ese dinero. El
 * selector listaba todas las cajas de la sede, así que bastaba con elegir la de
 * al lado y operarla.
 */
class TurnoAjenoTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private User $ana;
    private User $beto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->sj->cashRegisters()->create(['name'=>'Mostrador 2','is_active'=>true]);

        $this->ana  = $this->cajero('Ana Campos', 'ana');
        $this->beto = $this->cajero('Beto Rojas', 'beto');
    }

    private function cajero(string $nombre, string $usuario): User
    {
        return User::create([
            'name'=>$nombre,'username'=>$usuario,'email'=>$usuario.'@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$this->sj->id,
        ]);
    }

    /** Ana abre la primera caja; devuelve esa caja. */
    private function anaAbreSuTurno(): CashSession
    {
        $caja = $this->sj->cashRegisters()->orderBy('name')->firstOrFail();

        return app(CajaService::class)->abrir($caja, $this->ana, 10000);
    }

    private function betoEnLaCajaDeAna(CashSession $sesion)
    {
        return Livewire::actingAs($this->beto)
            ->test(CajaPanel::class)
            ->set('registerId', $sesion->cash_register_id);
    }

    // ── Lo reportado ──────────────────────────────────────────────────

    public function test_beto_no_puede_cerrar_el_turno_de_ana(): void
    {
        $sesion = $this->anaAbreSuTurno();

        $this->betoEnLaCajaDeAna($sesion)
            ->call('cerrar')
            ->assertSet('feedbackType', 'error')
            ->assertSee('responde por su arqueo');

        $this->assertSame(CashSession::STATUS_OPEN, $sesion->fresh()->status);
    }

    public function test_beto_no_puede_registrar_movimientos_en_el_turno_de_ana(): void
    {
        $sesion = $this->anaAbreSuTurno();

        $this->betoEnLaCajaDeAna($sesion)
            ->set('movementAmount', 5000)
            ->set('movementReason', 'algo')
            ->call('registrarMovimiento')
            ->assertSet('feedbackType', 'error');

        $this->assertSame(0, CashMovement::where('cash_session_id', $sesion->id)->count());
    }

    public function test_beto_no_puede_ni_abrir_el_arqueo(): void
    {
        $sesion = $this->anaAbreSuTurno();

        $this->betoEnLaCajaDeAna($sesion)
            ->call('abrirArqueo')
            ->assertSet('showArqueo', false)
            ->assertSet('feedbackType', 'error');
    }

    /** La pantalla lo dice en vez de ofrecer botones que van a fallar. */
    public function test_la_pantalla_avisa_de_quien_es_el_turno(): void
    {
        $sesion = $this->anaAbreSuTurno();

        $this->betoEnLaCajaDeAna($sesion)
            ->assertSee('Turno de Ana Campos')
            ->assertDontSee('Hacer arqueo');
    }

    // ── Lo que sí se puede ────────────────────────────────────────────

    public function test_ana_opera_su_propio_turno_sin_problema(): void
    {
        $sesion = $this->anaAbreSuTurno();

        Livewire::actingAs($this->ana)
            ->test(CajaPanel::class)
            ->set('registerId', $sesion->cash_register_id)
            ->set('movementAmount', 5000)
            ->set('movementReason', 'compra de bolsas')
            ->set('movementType', 'out')
            ->call('registrarMovimiento')
            ->assertSet('feedbackType', 'success');

        $this->assertSame(1, CashMovement::where('cash_session_id', $sesion->id)->count());
    }

    /** Beto abre la suya en la otra caja: dos turnos a la vez es lo normal. */
    public function test_beto_abre_su_turno_en_otra_caja(): void
    {
        $this->anaAbreSuTurno();
        $otra = $this->sj->cashRegisters()->where('name', 'Mostrador 2')->firstOrFail();

        Livewire::actingAs($this->beto)
            ->test(CajaPanel::class)
            ->set('registerId', $otra->id)
            ->set('openingFloat', 5000)
            ->call('abrir')
            ->assertSet('feedbackType', 'success');

        $this->assertSame(2, CashSession::where('status', CashSession::STATUS_OPEN)->count());
    }

    /** Alguien tiene que poder cerrar el turno de un cajero que se fue. */
    public function test_el_administrador_si_puede_cerrar_un_turno_ajeno(): void
    {
        $sesion = $this->anaAbreSuTurno();
        Denomination::firstOrCreate(['value' => 1000], ['sort_order' => 1, 'is_active' => true]);

        $admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'admin@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true,'branch_id'=>$this->sj->id]);

        Livewire::actingAs($admin)
            ->test(CajaPanel::class)
            ->set('registerId', $sesion->cash_register_id)
            ->call('abrirArqueo')
            ->assertSet('showArqueo', true)
            ->call('cerrar');

        $this->assertSame(CashSession::STATUS_CLOSED, $sesion->fresh()->status);
    }

    /** Una caja libre se puede tomar: no hay turno de nadie que respetar. */
    public function test_una_caja_sin_turno_abierto_se_puede_usar(): void
    {
        $libre = $this->sj->cashRegisters()->where('name', 'Mostrador 2')->firstOrFail();

        Livewire::actingAs($this->beto)
            ->test(CajaPanel::class)
            ->set('registerId', $libre->id)
            ->assertDontSee('responde por su arqueo')
            ->assertSee('Abrir turno');
    }
}
