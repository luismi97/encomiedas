<?php

namespace Tests\Feature\Hacienda;

use App\Livewire\Invoices\InvoiceShow;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Emitir el comprobante cuando el administrador lo decida.
 *
 * Antes solo existía comprobante si la guía llegaba a «entregada»: el observer
 * lo reservaba en ese momento y la pantalla se limitaba a enviarlo. Facturar
 * antes era imposible aunque el cobro ya estuviera hecho.
 *
 * Y cuando no aparecía el botón sobre una guía YA entregada, el mensaje culpaba
 * a la entrega —cuando la causa real era que faltaban datos de Hacienda—.
 */
class EmitirCuandoElAdminDecidaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        Tax::create(['name'=>'IVA','percent'=>13,'hacienda_code'=>'08','is_default'=>true,'is_active'=>true]);

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);
    }

    private function configurarHacienda(): void
    {
        $s = CompanySetting::instance();
        $s->enabled = true;
        $s->identification_number = '3101234567';
        $s->certificate_path = 'hacienda/certificados/prueba.p12';
        $s->certificate_pin = '1234';
        $s->atv_username = 'usuario@atv';
        $s->atv_password = 'clave';
        $s->save();
    }

    private function guia(string $estado = Invoice::STATUS_PENDING): Invoice
    {
        $guia = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->sj->id,
            'delivery_branch_id'=>$this->lim->id,'sender_name'=>'Marta','recipient_name'=>'José',
            'subtotal'=>10000,'discount_amount'=>0,'tax_total'=>1300,'total'=>11300,
            'created_by'=>$this->admin->id])->fresh();

        $guia->items()->create(['description'=>'Caja','price'=>10000]);

        if ($estado === Invoice::STATUS_CANCELLED) {
            return app(GuideStatusService::class)->anular($guia, $this->admin, 'prueba');
        }

        return $guia;
    }

    private function pantalla(Invoice $guia)
    {
        return Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $guia]);
    }

    // ── Lo pedido ─────────────────────────────────────────────────────

    /** Una guía recién recibida ya se puede facturar. */
    public function test_se_emite_sin_estar_entregada(): void
    {
        $this->configurarHacienda();
        $guia = $this->guia();

        $this->assertNull($guia->electronicInvoice);

        $this->pantalla($guia)->call('sendToHacienda');

        $this->assertNotNull($guia->fresh()->electronicInvoice);
        $this->assertSame(Invoice::STATUS_PENDING, $guia->fresh()->status,
            'Emitir no puede mover el estado de la guía.');
    }

    public function test_el_boton_aparece_aunque_no_este_entregada(): void
    {
        $this->configurarHacienda();

        $this->pantalla($this->guia())
            ->assertSee('Emitir y enviar a Hacienda')
            ->assertDontSee('debe estar');
    }

    /** El consecutivo se consume: conviene confirmarlo antes. */
    public function test_emitir_pide_confirmacion(): void
    {
        $this->configurarHacienda();

        $this->pantalla($this->guia())
            ->assertSee('wire:confirm', false)
            ->assertSee('consecutivo se consume');
    }

    public function test_emitir_dos_veces_no_duplica_el_comprobante(): void
    {
        $this->configurarHacienda();
        $guia = $this->guia();

        $this->pantalla($guia)->call('sendToHacienda');
        $primero = $guia->fresh()->electronicInvoice->id;

        $this->pantalla($guia->fresh())->call('sendToHacienda');

        $this->assertSame(1, ElectronicInvoice::where('invoice_id', $guia->id)->count());
        $this->assertSame($primero, $guia->fresh()->electronicInvoice->id);
    }

    // ── Lo que sigue sin poder hacerse ────────────────────────────────

    /** Facturar algo anulado deja un comprobante contra lo que no existe. */
    public function test_una_guia_anulada_no_se_factura(): void
    {
        $this->configurarHacienda();
        $guia = $this->guia(Invoice::STATUS_CANCELLED);

        $this->pantalla($guia)->call('sendToHacienda');

        $this->assertNull($guia->fresh()->electronicInvoice);
    }

    public function test_una_guia_anulada_no_muestra_el_boton(): void
    {
        $this->configurarHacienda();

        $this->pantalla($this->guia(Invoice::STATUS_CANCELLED))
            ->assertDontSee('Emitir y enviar a Hacienda')
            ->assertSee('está anulada');
    }

    // ── El motivo real cuando no se puede ─────────────────────────────

    /** El bug: decía que faltaba entregar, cuando faltaba configurar. */
    public function test_sin_configurar_dice_exactamente_que_falta(): void
    {
        $this->pantalla($this->guia())
            ->assertSee('facturación electrónica está incompleta')
            ->assertSee('El certificado digital')
            ->assertSee('El usuario de ATV')
            ->assertDontSee('debe estar');
    }

    public function test_la_lista_de_faltantes_se_reduce_al_ir_completando(): void
    {
        $s = CompanySetting::instance();

        $this->assertContains('La cédula del emisor', $s->faltantesParaFacturar());

        $s->enabled = true;
        $s->identification_number = '3101234567';
        $s->save();

        $faltantes = CompanySetting::instance()->fresh()->faltantesParaFacturar();

        $this->assertNotContains('La cédula del emisor', $faltantes);
        $this->assertContains('El certificado digital (.p12)', $faltantes);
    }

    public function test_configurado_del_todo_no_reporta_faltantes(): void
    {
        $this->configurarHacienda();

        $this->assertSame([], CompanySetting::instance()->fresh()->faltantesParaFacturar());
        $this->assertTrue(CompanySetting::instance()->fresh()->isReady());
    }

    /** Entregar sigue reservando el comprobante solo, como antes. */
    public function test_al_entregar_se_sigue_reservando_automaticamente(): void
    {
        $this->configurarHacienda();
        $guia = $this->guia();

        $estados = app(GuideStatusService::class);
        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $e) {
            $guia = $estados->cambiar($guia, $e, $this->admin);
        }
        $estados->entregar($guia, $this->admin, 'José');

        $this->assertNotNull($guia->fresh()->electronicInvoice);
    }
}
