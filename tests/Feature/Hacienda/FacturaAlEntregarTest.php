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
 * Quien retira pide factura con cédula aunque la guía se creó como tiquete.
 *
 * El comprobante se crea al entregar y ahí queda fijado si es FE o TE, así que
 * la entrega es el último momento para cambiarlo. Los montos no se tocan.
 */
class FacturaAlEntregarTest extends TestCase
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

        $s = CompanySetting::instance();
        $s->enabled = true;
        $s->identification_number = '3101234567';
        $s->certificate_path = 'hacienda/certificados/prueba.p12';
        $s->certificate_pin = '1234';
        $s->atv_username = 'usuario@atv';
        $s->atv_password = 'clave';
        $s->save();
    }

    private function guiaEnDestino(): Invoice
    {
        $guia = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->sj->id,
            'delivery_branch_id'=>$this->lim->id,'sender_name'=>'Marta','recipient_name'=>'José',
            'bill_type'=>Invoice::BILL_TICKET,
            'subtotal'=>10000,'discount_amount'=>0,'tax_total'=>1300,'total'=>11300,
            'created_by'=>$this->admin->id])->fresh();

        $guia->items()->create(['description'=>'Caja','price'=>10000]);

        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $estado) {
            $guia = app(GuideStatusService::class)->cambiar($guia, $estado, $this->admin);
        }

        return $guia;
    }

    private function pantalla(Invoice $guia)
    {
        return Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('openDeliveryForm')
            ->set('receivedByName', 'Ana Mora');
    }

    public function test_pedir_factura_al_entregar_emite_fe_a_su_nombre(): void
    {
        $guia = $this->guiaEnDestino();

        $this->pantalla($guia)
            ->assertSee('¿Quiere factura con cédula?')
            ->set('quiereFactura', true)
            ->set('facturaNombre', 'Ana Mora')
            ->set('facturaTipoId', '01')
            ->set('facturaId', '1-1234-0567')
            ->set('facturaEmail', 'ana@t.test')
            ->call('entregar')
            ->assertHasNoErrors();

        $guia->refresh();
        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->status);
        $this->assertSame('112340567', $guia->billing_identification);
        $this->assertEquals(11300, (float) $guia->total, 'Los montos no cambian.');

        $comprobante = $guia->electronicInvoice;
        $this->assertSame('01', $comprobante->document_type, 'Tiene que salir como Factura Electrónica.');
        $this->assertSame('112340567', $comprobante->receptor_data['numero']);
        $this->assertSame('Ana Mora', $comprobante->receptor_data['nombre']);
    }

    public function test_sin_pedirla_sigue_siendo_tiquete(): void
    {
        $guia = $this->guiaEnDestino();

        $this->pantalla($guia)->call('entregar')->assertHasNoErrors();

        $this->assertSame('04', $guia->fresh()->electronicInvoice->document_type);
    }

    public function test_la_cedula_es_obligatoria_y_no_entrega_sin_ella(): void
    {
        $guia = $this->guiaEnDestino();

        $this->pantalla($guia)
            ->set('quiereFactura', true)
            ->set('facturaId', '')
            ->call('entregar')
            ->assertHasErrors(['facturaId' => 'required']);

        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $guia->fresh()->status);
    }

    public function test_no_se_ofrece_si_ya_hay_comprobante(): void
    {
        $guia = $this->guiaEnDestino();
        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $guia])->call('sendToHacienda');
        $this->assertSame(1, ElectronicInvoice::count());

        $this->pantalla($guia->fresh())->assertDontSee('¿Quiere factura con cédula?');
    }

    public function test_no_se_ofrece_si_ya_va_como_factura(): void
    {
        $guia = $this->guiaEnDestino();
        $guia->forceFill(['bill_type'=>Invoice::BILL_INVOICE,'recipient_identification'=>'112340567'])->save();

        $this->pantalla($guia->fresh())->assertDontSee('¿Quiere factura con cédula?');
    }

    /**
     * Una guía que ya va como factura con cédula y ya tiene comprobante (se
     * emitió al cobrarla) se entrega sin más. El formulario precargaba esos
     * mismos datos como si quien retira los pidiera, y la entrega se trababa
     * con «no se puede cambiar a factura con cédula desde la entrega».
     */
    public function test_ya_facturada_con_cedula_y_con_comprobante_se_entrega(): void
    {
        $guia = $this->guiaEnDestino();
        $guia->forceFill(['bill_type'=>Invoice::BILL_INVOICE,'recipient_identification'=>'112340567'])->save();
        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $guia->fresh()])->call('sendToHacienda');
        $this->assertSame(1, ElectronicInvoice::count());

        $this->pantalla($guia->fresh())
            ->call('entregar')
            ->assertHasNoErrors()
            ->assertDontSee('ya tiene comprobante electrónico');

        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->fresh()->status);
        $this->assertSame(1, ElectronicInvoice::count(), 'No se emite otro comprobante.');
    }
}
