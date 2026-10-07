<?php

namespace Tests\Feature\Caja;

use App\Livewire\Caja\CajaPanel;
use App\Livewire\CashRegisters\CashRegisterIndex;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Una sede puede tener varias cajas: «Mostrador 1», «Mostrador 2».
 *
 * Cada una lleva su turno y su arqueo por separado, porque dos cajeros
 * cobrando a la vez sobre la misma gaveta hacen que el faltante de uno aparezca
 * en el conteo del otro.
 */
class MultiplesCajasTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj = Branch::create([
            'name' => 'San José', 'prefix' => 'SJ',
            'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
            'branch_id' => $this->sj->id,
        ]);
    }

    private function panel()
    {
        return Livewire::actingAs($this->admin)->test(CashRegisterIndex::class);
    }

    private function cajero(string $nombre, string $usuario): User
    {
        return User::create([
            'name' => $nombre, 'username' => $usuario, 'email' => $usuario . '@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
            'branch_id' => $this->sj->id,
        ]);
    }

    // ── Administración ────────────────────────────────────────────────

    public function test_se_agrega_una_segunda_caja_a_la_sede(): void
    {
        $this->panel()
            ->call('create')
            ->set('branch_id', $this->sj->id)
            ->set('name', 'Mostrador 2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, $this->sj->cashRegisters()->count());
        $this->assertEqualsCanonicalizing(
            [Branch::CAJA_PRINCIPAL, 'Mostrador 2'],
            $this->sj->cashRegisters()->pluck('name')->all()
        );
    }

    /** El nombre sugerido evita que el administrador tenga que inventarlo. */
    public function test_sugiere_el_nombre_segun_las_cajas_que_ya_hay(): void
    {
        $this->panel()
            ->call('create')
            ->set('branch_id', $this->sj->id)
            ->assertSet('name', 'Mostrador 2');
    }

    public function test_no_se_repite_el_nombre_dentro_de_la_misma_sede(): void
    {
        $this->panel()
            ->call('create')
            ->set('branch_id', $this->sj->id)
            ->set('name', Branch::CAJA_PRINCIPAL)
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(1, $this->sj->cashRegisters()->count());
    }

    /** Dos sedes sí pueden llamarle igual a su caja. */
    public function test_dos_sedes_pueden_tener_una_caja_con_el_mismo_nombre(): void
    {
        $lim = Branch::create([
            'name' => 'Limón', 'prefix' => 'LIM',
            'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true,
        ]);

        $this->assertSame(Branch::CAJA_PRINCIPAL, $this->sj->cashRegisters()->first()->name);
        $this->assertSame(Branch::CAJA_PRINCIPAL, $lim->cashRegisters()->first()->name);
    }

    // ── Operación simultánea, que es el punto de todo esto ────────────

    public function test_dos_cajas_de_la_misma_sede_abren_turno_a_la_vez(): void
    {
        $segunda = $this->sj->cashRegisters()->create(['name' => 'Mostrador 2', 'is_active' => true]);
        $primera = $this->sj->cashRegisters()->where('name', Branch::CAJA_PRINCIPAL)->firstOrFail();

        $ana = $this->cajero('Ana', 'ana');
        $beto = $this->cajero('Beto', 'beto');

        $servicio = app(CajaService::class);
        $servicio->abrir($primera, $ana, 10000);
        $servicio->abrir($segunda, $beto, 25000);

        $this->assertSame(2, CashSession::where('status', CashSession::STATUS_OPEN)->count());
        $this->assertSame(2, CashSession::where('branch_id', $this->sj->id)->count());
    }

    /** La misma caja sigue sin poder tener dos turnos abiertos. */
    public function test_una_misma_caja_no_abre_dos_turnos(): void
    {
        $caja = $this->sj->cashRegisters()->firstOrFail();
        $servicio = app(CajaService::class);

        $servicio->abrir($caja, $this->cajero('Ana', 'ana'), 10000);

        $this->expectExceptionMessage('ya tiene un turno abierto');
        $servicio->abrir($caja, $this->cajero('Beto', 'beto'), 5000);
    }

    /** Con dos turnos en la misma sede no sabría a cuál arqueo entra cada cobro. */
    public function test_un_cajero_no_abre_dos_cajas_a_la_vez(): void
    {
        $primera = $this->sj->cashRegisters()->firstOrFail();
        $segunda = $this->sj->cashRegisters()->create(['name' => 'Mostrador 2', 'is_active' => true]);
        $ana = $this->cajero('Ana', 'ana');
        $servicio = app(CajaService::class);

        $servicio->abrir($primera, $ana, 10000);

        try {
            $servicio->abrir($segunda, $ana, 5000);
            $this->fail('Abrió una segunda caja con la primera todavía abierta.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Ya tenés abierta la caja «' . Branch::CAJA_PRINCIPAL . '»', $e->getMessage());
        }

        $this->assertSame(1, CashSession::where('opened_by', $ana->id)->count());
    }

    private function limon(): Branch
    {
        return Branch::create(['name' => 'Limón', 'prefix' => 'LIM',
            'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);
    }

    /**
     * En otra sede sí: quien atiende dos sucursales lleva un turno en cada
     * una, y cada cobro va al de la sede donde se cobra la guía.
     */
    public function test_abre_una_caja_en_otra_sede_con_la_suya_abierta(): void
    {
        $lim = $this->limon();
        $ana = $this->cajero('Ana', 'ana');
        $servicio = app(CajaService::class);

        $enSj = $servicio->abrir($this->sj->cashRegisters()->firstOrFail(), $ana, 10000);
        $enLim = $servicio->abrir($lim->cashRegisters()->firstOrFail(), $ana, 5000);

        $this->assertTrue($enSj->fresh()->estaAbierta());
        $this->assertTrue($enLim->estaAbierta());
        $this->assertSame($enSj->id, $servicio->sesionPropiaAbierta($ana, $this->sj->id)->id);
        $this->assertSame($enLim->id, $servicio->sesionPropiaAbierta($ana, $lim->id)->id);
    }

    /** El administrador también: puede cubrir dos sedes el mismo día. */
    public function test_el_administrador_abre_una_caja_por_sede(): void
    {
        $lim = $this->limon();
        $servicio = app(CajaService::class);

        $servicio->abrir($this->sj->cashRegisters()->firstOrFail(), $this->admin, 0);
        $servicio->abrir($lim->cashRegisters()->firstOrFail(), $this->admin, 0);

        $this->assertSame(2, CashSession::where('opened_by', $this->admin->id)
            ->where('status', CashSession::STATUS_OPEN)->count());
    }

    /** Con un turno en cada sede, el cobro no se cruza de gaveta. */
    public function test_cada_cobro_entra_al_turno_de_su_sede(): void
    {
        $lim = $this->limon();
        $ana = $this->cajero('Ana', 'ana');
        $servicio = app(CajaService::class);

        $enSj = $servicio->abrir($this->sj->cashRegisters()->firstOrFail(), $ana, 0);
        $enLim = $servicio->abrir($lim->cashRegisters()->firstOrFail(), $ana, 0);

        $guia = \App\Models\Invoice::create([
            'status' => \App\Models\Invoice::STATUS_PENDING,
            'pickup_branch_id' => $lim->id, 'delivery_branch_id' => $this->sj->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 3000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 3000,
            'payment_method' => 'cash', 'created_by' => $ana->id,
        ])->fresh();

        $movimiento = $servicio->registrarCobro($guia, $ana);

        $this->assertSame($enLim->id, $movimiento->cash_session_id);
        $this->assertSame(0, $enSj->movements()->count());
    }

    /** El panel deja abrir en la otra sede y ofrece saltar entre los turnos. */
    public function test_el_panel_abre_en_otra_sede_y_lista_los_turnos(): void
    {
        $lim = $this->limon();
        $ana = $this->cajero('Ana', 'ana');
        $ana->branches()->attach($lim->id);
        $cajaLim = $lim->cashRegisters()->firstOrFail();

        app(CajaService::class)->abrir($this->sj->cashRegisters()->firstOrFail(), $ana, 10000);

        Livewire::actingAs($ana)->test(CajaPanel::class)
            ->set('registerId', $cajaLim->id)
            ->assertDontSeeHtml('data-test="turno-propio-en-otra"')
            ->assertSeeHtml('data-test="fondo-inicial"')
            ->set('openingFloat', 5000)
            ->call('abrir')
            ->assertSee('Turno abierto')
            ->assertSeeHtml('data-test="mis-turnos"');

        $this->assertNotNull($cajaLim->fresh()->sesionAbierta());
    }

    public function test_cerrada_la_suya_puede_abrir_otra(): void
    {
        $primera = $this->sj->cashRegisters()->firstOrFail();
        $segunda = $this->sj->cashRegisters()->create(['name' => 'Mostrador 2', 'is_active' => true]);
        $ana = $this->cajero('Ana', 'ana');
        $servicio = app(CajaService::class);

        $turno = $servicio->abrir($primera, $ana, 10000);
        $servicio->cerrar($turno->fresh(), $ana, []);

        $this->assertTrue($servicio->abrir($segunda, $ana, 5000)->estaAbierta());
    }

    /** En pantalla se avisa antes de intentarlo, y no se ofrece abrir. */
    public function test_el_panel_avisa_que_primero_cierre_la_suya(): void
    {
        $primera = $this->sj->cashRegisters()->firstOrFail();
        $segunda = $this->sj->cashRegisters()->create(['name' => 'Mostrador 2', 'is_active' => true]);
        $ana = $this->cajero('Ana', 'ana');

        app(CajaService::class)->abrir($primera, $ana, 10000);

        Livewire::actingAs($ana)->test(CajaPanel::class)
            ->set('registerId', $segunda->id)
            ->assertSeeHtml('data-test="turno-propio-en-otra"')
            ->assertDontSeeHtml('data-test="fondo-inicial"')
            ->call('abrir')
            ->assertSee('Cerrala antes de abrir otra');

        $this->assertNull($segunda->fresh()->sesionAbierta());
    }

    /** Llegar y encontrar el turno de un compañero metería el cobro en su gaveta. */
    public function test_el_panel_ofrece_una_caja_libre_y_no_la_del_companero(): void
    {
        $ocupada = $this->sj->cashRegisters()->firstOrFail();
        $libre = $this->sj->cashRegisters()->create(['name' => 'Mostrador 2', 'is_active' => true]);

        app(CajaService::class)->abrir($ocupada, $this->cajero('Ana', 'ana'), 10000);

        Livewire::actingAs($this->cajero('Beto', 'beto'))
            ->test(CajaPanel::class)
            ->assertSet('registerId', $libre->id);
    }

    /** Con su propio turno abierto, el cajero vuelve a ese y no a otro. */
    public function test_el_cajero_vuelve_a_su_propio_turno(): void
    {
        $primera = $this->sj->cashRegisters()->firstOrFail();
        $this->sj->cashRegisters()->create(['name' => 'Mostrador 2', 'is_active' => true]);

        $ana = $this->cajero('Ana', 'ana');
        app(CajaService::class)->abrir($primera, $ana, 10000);

        Livewire::actingAs($ana)
            ->test(CajaPanel::class)
            ->assertSet('registerId', $primera->id);
    }

    public function test_el_selector_agrupa_las_cajas_por_sede(): void
    {
        $this->sj->cashRegisters()->create(['name' => 'Mostrador 2', 'is_active' => true]);
        Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);

        Livewire::actingAs($this->admin)
            ->test(CajaPanel::class)
            ->assertSee('<optgroup label="San José">', false)
            ->assertSee('<optgroup label="Limón">', false)
            ->assertSee('Mostrador 2');
    }

    // ── Lo que no se puede hacer ──────────────────────────────────────

    public function test_no_se_desactiva_una_caja_con_turno_abierto(): void
    {
        $caja = $this->sj->cashRegisters()->firstOrFail();
        app(CajaService::class)->abrir($caja, $this->cajero('Ana', 'ana'), 10000);

        $this->panel()
            ->call('toggleActive', $caja->id)
            ->assertSet('feedbackType', 'error')
            ->assertSee('turno abierto');

        $this->assertTrue($caja->fresh()->is_active);
    }

    /** Un arqueo cerrado es un documento contable: borrar la caja lo arrastraba. */
    public function test_no_se_elimina_una_caja_con_historial(): void
    {
        $caja = $this->sj->cashRegisters()->firstOrFail();
        $this->sj->cashRegisters()->create(['name' => 'Mostrador 2', 'is_active' => true]);

        app(CajaService::class)->abrir($caja, $this->cajero('Ana', 'ana'), 10000);

        $this->panel()
            ->call('delete', $caja->id)
            ->assertSet('feedbackType', 'error')
            ->assertSee('documento contable');

        $this->assertNotNull($caja->fresh());
        $this->assertSame(1, CashSession::count());
    }

    public function test_no_se_elimina_la_unica_caja_de_la_sede(): void
    {
        $caja = $this->sj->cashRegisters()->firstOrFail();

        $this->panel()
            ->call('delete', $caja->id)
            ->assertSet('feedbackType', 'error')
            ->assertSee('única caja');

        $this->assertNotNull($caja->fresh());
    }

    public function test_una_caja_sin_historial_si_se_elimina(): void
    {
        $extra = $this->sj->cashRegisters()->create(['name' => 'Mostrador 2', 'is_active' => true]);

        $this->panel()
            ->call('delete', $extra->id)
            ->assertSet('feedbackType', 'success');

        $this->assertNull(CashRegister::find($extra->id));
    }

    /** Moverla de sede dejaría sus arqueos contabilizados donde no ocurrieron. */
    public function test_no_se_cambia_de_sede_una_caja_con_historial(): void
    {
        $lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);
        $caja = $this->sj->cashRegisters()->firstOrFail();

        app(CajaService::class)->abrir($caja, $this->cajero('Ana', 'ana'), 10000);

        $this->panel()
            ->call('edit', $caja->id)
            ->set('branch_id', $lim->id)
            ->call('save')
            ->assertSet('feedbackType', 'error')
            ->assertSee('no se puede cambiar de sede');

        $this->assertSame($this->sj->id, $caja->fresh()->branch_id);
    }

    public function test_solo_el_administrador_entra_a_la_pantalla_de_cajas(): void
    {
        $this->actingAs($this->cajero('Ana', 'ana'))
            ->get(route('cash-registers.index'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('cash-registers.index'))
            ->assertOk();
    }

    /** La caja viene preseleccionada: antes de abrir, confirma cuál y en qué sede. */
    public function test_abrir_caja_confirma_la_caja_y_la_sede(): void
    {
        $mostrador = $this->sj->cashRegisters()->create(['name' => 'Mostrador 2', 'is_active' => true]);

        Livewire::actingAs($this->cajero('Ana', 'ana'))
            ->test(CajaPanel::class)
            ->set('registerId', $mostrador->id)
            ->assertSeeHtml('wire:confirm="¿Abrir el turno en «Mostrador 2» de la sede San José?"');
    }
}
