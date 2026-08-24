<?php

namespace Tests\Feature\Cobro;

use App\Livewire\Invoices\InvoiceShow;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CajaService;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A dónde va la plata de un flete por cobrar.
 *
 * Se cobra al entregar, en la caja de destino. Si no había caja abierta el
 * cobro se perdía dejando solo una línea en el log: la guía quedaba entregada
 * y el dinero no figuraba en ningún arqueo. Y aun cuando sí se registraba, la
 * guía no decía dónde había entrado.
 */
class CobroEnDestinoTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $enOrigen;
    private User $enDestino;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);

        $this->enOrigen  = User::create(['name'=>'Ana','username'=>'ana','email'=>'ana@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_ADMIN,'is_active'=>true,'branch_id'=>$this->sj->id]);
        $this->enDestino = User::create(['name'=>'Beto Rojas','username'=>'beto','email'=>'beto@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_ADMIN,'is_active'=>true,'branch_id'=>$this->lim->id]);
    }

    private function guiaPorCobrarEnDestino(): Invoice
    {
        $guia = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->sj->id,
            'delivery_branch_id'=>$this->lim->id,'sender_name'=>'Marta','recipient_name'=>'José',
            'payment_timing'=>Invoice::TIMING_COLLECT,'payment_method'=>'cash',
            'subtotal'=>11300,'discount_amount'=>0,'tax_total'=>0,'total'=>11300,
            'created_by'=>$this->enOrigen->id])->fresh();

        $estados = app(GuideStatusService::class);
        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $e) {
            $guia = $estados->cambiar($guia, $e, $this->enDestino);
        }

        return $guia;
    }

    private function abrirCajaEnDestino()
    {
        return app(CajaService::class)->abrir(
            $this->lim->cashRegisters()->firstOrFail(), $this->enDestino, 5000
        );
    }

    // ── Lo reportado ──────────────────────────────────────────────────

    /** El dinero se perdía con una línea de log y la guía quedaba entregada. */
    public function test_no_se_entrega_un_por_cobrar_sin_caja_en_destino(): void
    {
        $guia = $this->guiaPorCobrarEnDestino();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POR COBRAR');

        app(GuideStatusService::class)->entregar($guia, $this->enDestino, 'José Fernández');
    }

    public function test_la_guia_no_queda_entregada_si_el_cobro_no_puede_entrar(): void
    {
        $guia = $this->guiaPorCobrarEnDestino();

        try {
            app(GuideStatusService::class)->entregar($guia, $this->enDestino, 'José');
        } catch (RuntimeException $e) {
            // esperado
        }

        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $guia->fresh()->status);
        $this->assertNull($guia->fresh()->collected_at);
    }

    public function test_el_mensaje_dice_el_monto_y_que_hacer(): void
    {
        $guia = $this->guiaPorCobrarEnDestino();

        // Contra la pantalla y no contra la sesión: <x-flash /> consume el
        // mensaje con pull() al renderizar, así que ya no está en session().
        Livewire::actingAs($this->enDestino)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('openDeliveryForm')
            ->set('receivedByName', 'José')
            ->call('entregar')
            ->assertSee('11,300.00')
            ->assertSee('Abrí la caja');
    }

    // ── Con la caja abierta ───────────────────────────────────────────

    public function test_el_cobro_entra_a_la_caja_de_destino(): void
    {
        $sesion = $this->abrirCajaEnDestino();
        $guia = $this->guiaPorCobrarEnDestino();

        app(GuideStatusService::class)->entregar($guia, $this->enDestino, 'José Fernández');

        $movimiento = CashMovement::where('invoice_id', $guia->id)->firstOrFail();

        $this->assertSame($sesion->id, $movimiento->cash_session_id);
        $this->assertEquals(11300, $movimiento->amount);
        $this->assertNotNull($guia->fresh()->collected_at);
    }

    /** Y NO a la de origen, donde ese dinero nunca estuvo. */
    public function test_no_entra_a_la_caja_de_origen(): void
    {
        $enOrigen = app(CajaService::class)->abrir(
            $this->sj->cashRegisters()->firstOrFail(), $this->enOrigen, 5000
        );
        $this->abrirCajaEnDestino();
        $guia = $this->guiaPorCobrarEnDestino();

        app(GuideStatusService::class)->entregar($guia, $this->enDestino, 'José');

        $this->assertSame(0, CashMovement::where('cash_session_id', $enOrigen->id)->count());
    }

    // ── Que se vea dónde fue ──────────────────────────────────────────

    /** La pregunta que la guía no sabía responder. */
    public function test_la_guia_dice_a_que_caja_entro_el_dinero(): void
    {
        $this->abrirCajaEnDestino();
        $guia = $this->guiaPorCobrarEnDestino();

        app(GuideStatusService::class)->entregar($guia, $this->enDestino, 'José Fernández');

        Livewire::actingAs($this->enDestino)
            ->test(InvoiceShow::class, ['invoice' => $guia->fresh()])
            ->assertSee('Cobrado al entregar')
            ->assertSee('11,300.00')
            ->assertSee('Caja principal')
            ->assertSee('Limón')
            ->assertSee('Beto Rojas');
    }

    public function test_antes_de_entregar_se_muestra_como_pendiente(): void
    {
        $guia = $this->guiaPorCobrarEnDestino();

        Livewire::actingAs($this->enDestino)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertSee('Pendiente de cobro')
            ->assertSee('11,300.00');
    }

    /** Una guía pagada en origen no muestra nada de esto. */
    public function test_una_guia_pagada_no_muestra_cobro_pendiente(): void
    {
        $guia = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->sj->id,
            'delivery_branch_id'=>$this->lim->id,'sender_name'=>'M','recipient_name'=>'J',
            'payment_timing'=>Invoice::TIMING_PREPAID,
            'subtotal'=>5000,'discount_amount'=>0,'tax_total'=>0,'total'=>5000,
            'created_by'=>$this->enOrigen->id])->fresh();

        Livewire::actingAs($this->enOrigen)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertDontSee('Pendiente de cobro');
    }
}
