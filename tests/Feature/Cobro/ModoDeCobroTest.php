<?php

namespace Tests\Feature\Cobro;

use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\CajaService;
use App\Services\CreditoService;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Qué pasa con la plata de cada guía.
 *
 * El formulario nunca asignaba la condición de venta: toda guía se guardaba
 * como contado pagado. Un cliente con convenio enviaba y su saldo no se movía,
 * el límite de crédito nunca se revisaba —bloqueoPorLimite() existía y nadie lo
 * llamaba— y un flete por cobrar entraba al arqueo de una caja donde ese dinero
 * jamás estuvo.
 */
class ModoDeCobroTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        Tax::create(['name'=>'IVA','percent'=>13,'hacienda_code'=>'08','is_default'=>true,'is_active'=>true]);

        $this->cajero = User::create(['name'=>'Ana','username'=>'ana','email'=>'ana@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_ADMIN,'is_active'=>true,'branch_id'=>$this->sj->id]);
    }

    private function abrirCaja(Branch $sede, ?User $quien = null)
    {
        return app(CajaService::class)->abrir($sede->cashRegisters()->firstOrFail(), $quien ?? $this->cajero, 10000);
    }

    private function clienteDeCredito(float $limite = 100000): Customer
    {
        return Customer::create([
            'name' => 'Ferretería El Roble', 'identification' => '310112345678',
            'payment_condition' => Customer::PAYMENT_CREDIT, 'credit_limit' => $limite,
            'credit_cutoff_day' => 30, 'is_active' => true,
        ]);
    }

    private function formulario()
    {
        return Livewire::actingAs($this->cajero)
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.price', 10000);
    }

    // ── Pagado ────────────────────────────────────────────────────────

    public function test_una_guia_pagada_entra_al_arqueo_de_origen(): void
    {
        $sesion = $this->abrirCaja($this->sj);

        $this->formulario()->set('cobro', 'prepaid')->call('save')->assertHasNoErrors();

        $guia = Invoice::firstOrFail();
        $this->assertSame(Invoice::SALE_CASH, $guia->sale_condition);
        $this->assertFalse($guia->esPorCobrar());
        $this->assertSame(1, CashMovement::where('cash_session_id', $sesion->id)
            ->where('invoice_id', $guia->id)->count());
    }

    // ── Por cobrar ────────────────────────────────────────────────────

    public function test_un_por_cobrar_no_entra_al_arqueo_de_origen(): void
    {
        $sesion = $this->abrirCaja($this->sj);

        $this->formulario()->set('cobro', 'collect')->call('save')->assertHasNoErrors();

        $guia = Invoice::firstOrFail();
        $this->assertTrue($guia->esPorCobrar());
        $this->assertTrue($guia->tieneCobroPendiente());
        $this->assertSame(0, CashMovement::where('cash_session_id', $sesion->id)->count(),
            'El dinero nunca estuvo en la gaveta de origen.');
    }

    /** Y sí entra en destino, al entregarla. */
    public function test_un_por_cobrar_entra_al_arqueo_de_destino_al_entregar(): void
    {
        $this->formulario()->set('cobro', 'collect')->call('save');
        $guia = Invoice::firstOrFail();

        $enDestino = User::create(['name'=>'Beto','username'=>'beto','email'=>'b@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_ADMIN,'is_active'=>true,'branch_id'=>$this->lim->id]);
        $sesionDestino = $this->abrirCaja($this->lim, $enDestino);

        $estados = app(GuideStatusService::class);
        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $e) {
            $guia = $estados->cambiar($guia, $e, $enDestino);
        }
        $estados->entregar($guia, $enDestino, 'José Fernández', '108880777');

        $this->assertSame(1, CashMovement::where('cash_session_id', $sesionDestino->id)
            ->where('invoice_id', $guia->id)->count());
        $this->assertNotNull($guia->fresh()->collected_at);
        $this->assertFalse($guia->fresh()->tieneCobroPendiente());
    }

    public function test_la_etiqueta_avisa_del_cobro_pendiente(): void
    {
        $this->formulario()->set('cobro', 'collect')->call('save');

        $this->actingAs($this->cajero)
            ->get(route('invoices.etiqueta', Invoice::firstOrFail()))
            ->assertSee('POR COBRAR')
            ->assertSee('11,300.00'); // 10000 + IVA
    }

    public function test_una_guia_pagada_no_dice_por_cobrar(): void
    {
        $this->formulario()->set('cobro', 'prepaid')->call('save');

        $this->actingAs($this->cajero)
            ->get(route('invoices.etiqueta', Invoice::firstOrFail()))
            ->assertDontSee('POR COBRAR');
    }

    // ── Crédito ───────────────────────────────────────────────────────

    /** El bug reportado: el saldo del cliente no se movía. */
    public function test_una_guia_a_credito_suma_al_saldo_del_cliente(): void
    {
        $cliente = $this->clienteDeCredito();
        $credito = app(CreditoService::class);

        $this->assertSame(0.0, $credito->saldoTotal($cliente));

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->set('cobro', 'credit')
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::firstOrFail();
        $this->assertTrue($guia->esCredito());
        $this->assertEqualsWithDelta((float) $guia->total, $credito->saldoTotal($cliente), 0.01);
        $this->assertSame(1, $credito->guiasPendientesDeCorte($cliente)->count());
    }

    public function test_elegir_un_cliente_con_convenio_propone_el_credito(): void
    {
        $cliente = $this->clienteDeCredito();

        Livewire::actingAs($this->cajero)
            ->test(InvoiceForm::class)
            ->set('sender_customer_id', $cliente->id)
            ->assertSet('cobro', 'credit')
            ->assertSee('Disponible');
    }

    public function test_un_cliente_de_contado_no_habilita_el_credito(): void
    {
        $contado = Customer::create(['name'=>'Juan','payment_condition'=>Customer::PAYMENT_CASH,'is_active'=>true]);

        Livewire::actingAs($this->cajero)
            ->test(InvoiceForm::class)
            ->set('sender_customer_id', $contado->id)
            ->assertSet('cobro', 'prepaid');
    }

    public function test_no_se_deja_a_credito_sin_remitente_registrado(): void
    {
        $this->formulario()
            ->set('cobro', 'credit')
            ->call('save')
            ->assertHasErrors('cobro')
            ->assertSee('elegir al remitente');

        $this->assertSame(0, Invoice::count());
    }

    /** El control existía en CreditoService y nadie lo llamaba. */
    public function test_pasarse_del_limite_bloquea_la_guia(): void
    {
        $cliente = $this->clienteDeCredito(limite: 5000);

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->set('cobro', 'credit')
            ->call('save')
            ->assertHasErrors('cobro')
            ->assertSee('Le quedan');

        $this->assertSame(0, Invoice::count());
    }

    public function test_dentro_del_limite_la_guia_pasa(): void
    {
        $cliente = $this->clienteDeCredito(limite: 50000);

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->set('cobro', 'credit')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Invoice::count());
    }

    public function test_una_guia_a_credito_no_entra_a_ninguna_caja(): void
    {
        $sesion = $this->abrirCaja($this->sj);
        $cliente = $this->clienteDeCredito();

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->set('cobro', 'credit')
            ->call('save');

        $this->assertSame(0, CashMovement::where('cash_session_id', $sesion->id)->count());
    }

    /** Al editar, el modo elegido no se pierde. */
    public function test_al_editar_se_conserva_el_modo_de_cobro(): void
    {
        $this->formulario()->set('cobro', 'collect')->call('save');
        $guia = Invoice::firstOrFail();

        Livewire::actingAs($this->cajero)
            ->test(InvoiceForm::class, ['invoice' => $guia])
            ->assertSet('cobro', 'collect');
    }
}
