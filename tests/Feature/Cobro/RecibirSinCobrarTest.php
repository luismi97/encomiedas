<?php

namespace Tests\Feature\Cobro;

use App\Livewire\Caja\CajaPanel;
use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\Users\UserIndex;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\CajaService;
use App\Services\DispatchService;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * En una sede hay quien solo recibe paquetes y quien cobra.
 *
 * Antes, guardar una guía de contado exigía que quien la registraba tuviera su
 * caja abierta: el que solo recibe no podía hacerlo sin marcarla «por cobrar» o
 * «a crédito», que no es lo que pasó. Ahora su guía queda esperando el pago en
 * caja, el cajero la cobra en su turno, y el paquete no sale hasta entonces.
 */
class RecibirSinCobrarTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $recepcion;
    private User $cajera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        Tax::create(['name'=>'IVA','percent'=>13,'hacienda_code'=>'08','is_default'=>true,'is_active'=>true]);

        $this->recepcion = User::create(['name'=>'Rosa','username'=>'rosa','email'=>'rosa@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$this->sj->id,'can_collect'=>false]);

        $this->cajera = User::create(['name'=>'Ana','username'=>'ana','email'=>'ana@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$this->sj->id]);
    }

    private function recibir(string $cobro = 'prepaid'): Invoice
    {
        Livewire::actingAs($this->recepcion)
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.price', 10000)
            ->set('payment_method', 'sinpe')
            ->set('cobro', $cobro)
            ->call('save')
            ->assertHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    private function abrirCaja(User $quien, Branch $sede)
    {
        // Con la sesión de quien la abre: las cajas también se filtran por sede.
        $this->actingAs($quien);

        return app(CajaService::class)->abrir($sede->cashRegisters()->firstOrFail(), $quien, 0);
    }

    // ── Recibir ───────────────────────────────────────────────────────

    public function test_quien_no_cobra_registra_una_guia_de_contado_sin_caja(): void
    {
        $guia = $this->recibir();

        $this->assertTrue($guia->esperandoCaja());
        $this->assertSame(0, CashMovement::count(), 'Nadie cobró todavía.');
        $this->assertSame(0, Invoice::cobradas()->count(), 'No es dinero recibido.');
    }

    /** El que cobra sigue necesitando su caja: eso no cambia. */
    public function test_quien_cobra_sigue_necesitando_caja_abierta(): void
    {
        Livewire::actingAs($this->cajera)
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.price', 10000)
            ->set('cobro', 'prepaid')
            ->call('save')
            ->assertHasErrors('cobro');
    }

    public function test_un_por_cobrar_de_quien_no_cobra_no_queda_esperando_caja(): void
    {
        $this->assertFalse($this->recibir('collect')->esperandoCaja());
    }

    public function test_quien_no_cobra_no_abre_caja(): void
    {
        Livewire::actingAs($this->recepcion)->test(CajaPanel::class)->assertForbidden();

        $this->actingAs($this->recepcion)->get(route('invoices.index'))
            ->assertDontSee(route('caja.index'));
    }

    // ── Cobrar en caja ────────────────────────────────────────────────

    public function test_la_cajera_la_cobra_en_su_turno_con_el_medio_que_elige(): void
    {
        $guia = $this->recibir();
        $sesion = $this->abrirCaja($this->cajera, $this->sj);

        Livewire::actingAs($this->cajera)
            ->test(CajaPanel::class)
            ->assertSee($guia->code)
            ->assertSet("medios.{$guia->id}", 'sinpe')
            ->set("medios.{$guia->id}", 'card')
            ->call('cobrar', $guia->id)
            ->assertSee('cobrada');

        $guia->refresh();

        $this->assertFalse($guia->esperandoCaja());
        $this->assertSame('card', $guia->payment_method);
        $this->assertSame(1, CashMovement::where('cash_session_id', $sesion->id)->where('invoice_id', $guia->id)->count());
        $this->assertSame(1, Invoice::cobradas()->count());
    }

    /** Dos clics, o dos cajeras a la vez, no meten el mismo dinero dos veces. */
    public function test_no_se_cobra_dos_veces(): void
    {
        $guia = $this->recibir();
        $this->abrirCaja($this->cajera, $this->sj);

        app(CajaService::class)->cobrarEnCaja($guia, $this->cajera, 'cash');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya está cobrada');

        app(CajaService::class)->cobrarEnCaja($guia, $this->cajera, 'cash');
    }

    public function test_quien_no_cobra_no_puede_cobrarla(): void
    {
        $guia = $this->recibir();

        $this->expectException(RuntimeException::class);

        app(CajaService::class)->cobrarEnCaja($guia, $this->recepcion, 'cash');
    }

    /** Editarla no la cobra: se cobra en la caja, no guardando el formulario. */
    public function test_editarla_no_la_da_por_cobrada(): void
    {
        $guia = $this->recibir();
        $this->abrirCaja($this->cajera, $this->sj);

        Livewire::actingAs($this->cajera)
            ->test(InvoiceForm::class, ['invoice' => $guia])
            ->set('recipient_name', 'José Fernández')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($guia->fresh()->esperandoCaja());
        $this->assertSame(0, CashMovement::count());
    }

    // ── Despacho ──────────────────────────────────────────────────────

    public function test_sin_cobrar_no_sale_en_el_cierre(): void
    {
        $guia = $this->recibir();

        $cierre = Dispatch::create(['code' => 'CIE-000001', 'origin_branch_id' => $this->sj->id,
            'destination_branch_id' => $this->lim->id, 'created_by' => $this->cajera->id]);

        try {
            app(DispatchService::class)->agregarGuia($cierre, $guia);
            $this->fail('Una guía sin cobrar no debería poder agregarse al cierre.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('todavía no se cobró en caja', $e->getMessage());
        }

        $this->abrirCaja($this->cajera, $this->sj);
        app(CajaService::class)->cobrarEnCaja($guia, $this->cajera, 'cash');

        app(DispatchService::class)->agregarGuia($cierre, $guia->fresh());
        $this->assertSame(1, $cierre->guides()->count());
    }

    // ── Por cobrar en destino ─────────────────────────────────────────

    /**
     * En destino pasa lo mismo: quien entrega puede no cobrar. El cajero cobra
     * el «por cobrar» desde la caja y después cualquiera entrega.
     */
    public function test_un_por_cobrar_se_cobra_en_caja_y_despues_se_entrega(): void
    {
        $guia = $this->recibir('collect');
        $guia->forceFill(['status' => Invoice::STATUS_AT_DESTINATION])->save();

        $entregaLimon = User::create(['name'=>'Luis','username'=>'luis','email'=>'luis@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$this->lim->id,'can_collect'=>false]);
        $cajaLimon = User::create(['name'=>'Eva','username'=>'eva','email'=>'eva@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$this->lim->id]);

        try {
            app(GuideStatusService::class)->entregar($guia, $entregaLimon, 'José');
            $this->fail('Quien no cobra no debería entregar un por cobrar sin cobrar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Tu usuario no cobra', $e->getMessage());
        }

        $sesion = $this->abrirCaja($cajaLimon, $this->lim);

        Livewire::actingAs($cajaLimon)->test(CajaPanel::class)->assertSee($guia->code)->call('cobrar', $guia->id);

        $this->assertNotNull($guia->fresh()->collected_at);

        app(GuideStatusService::class)->entregar($guia->fresh(), $entregaLimon, 'José');

        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->fresh()->status);
        $this->assertSame(1, CashMovement::where('cash_session_id', $sesion->id)->count(), 'Cobrada una sola vez.');
    }

    // ── Usuarios ──────────────────────────────────────────────────────

    public function test_la_casilla_se_guarda_en_el_cajero(): void
    {
        $admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'adm@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_ADMIN,'is_active'=>true]);

        Livewire::actingAs($admin)
            ->test(UserIndex::class)
            ->call('edit', $this->cajera->id)
            ->assertSet('can_collect', true)
            ->set('can_collect', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($this->cajera->fresh()->puedeCobrar());
    }

    public function test_un_cajero_nuevo_viene_marcado_para_cobrar(): void
    {
        $admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'adm@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_ADMIN,'is_active'=>true]);

        Livewire::actingAs($admin)
            ->test(UserIndex::class)
            ->call('create')
            ->set('role', User::ROLE_CAJERO)
            ->assertSet('can_collect', true)
            ->assertSeeHtml('data-test="puede-cobrar"');
    }

    /** Cambiarle el rol a cajero a alguien no hereda una casilla desmarcada. */
    public function test_pasar_a_cajero_marca_la_casilla(): void
    {
        $admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'adm@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_ADMIN,'is_active'=>true]);

        Livewire::actingAs($admin)
            ->test(UserIndex::class)
            ->call('edit', $this->cajera->id)
            ->set('can_collect', false)
            ->set('role', User::ROLE_REPARTIDOR)
            ->set('role', User::ROLE_CAJERO)
            ->assertSet('can_collect', true);
    }

    /** El administrador siempre cobra, aunque la casilla quede desmarcada. */
    public function test_el_administrador_siempre_cobra(): void
    {
        $admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'adm@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_ADMIN,'is_active'=>true,'can_collect'=>false]);

        $this->assertTrue($admin->puedeCobrar());
    }
}
