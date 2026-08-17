<?php

namespace Tests\Feature\Invoices;

use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Factura Electrónica vs Tiquete Electrónico. Antes se deducía de si venía la
 * cédula: quien la digitaba "por si acaso" emitía una FE sin quererlo, y no se
 * podía exigir porque no había forma de saber si hacía falta.
 */
class TipoComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@t.test'],
            ['name' => 'Admin', 'username' => 'admin', 'password' => bcrypt('x'),
             'role' => User::ROLE_ADMIN, 'is_active' => true]
        );
    }

    private function formulario()
    {
        $a = Branch::create(['name' => 'San José', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $b = Branch::create(['name' => 'Alajuela', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        Tax::create(['name' => 'IVA general', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);

        return Livewire::actingAs($this->admin())
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $a->id)
            ->set('delivery_branch_id', $b->id)
            ->set('sender_name', 'Marta Solano')
            ->set('recipient_name', 'José Fernández')
            ->set('items.0.package_code', 'PKG-001')
            ->set('items.0.price', 10000);
    }

    public function test_por_defecto_se_emite_tiquete(): void
    {
        $this->formulario()->assertSet('wantsInvoice', false)->call('save')->assertHasNoErrors();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(Invoice::BILL_TICKET, $invoice->bill_type);
        $this->assertFalse($invoice->receptorIdentificado());
        $this->assertSame('Tiquete electrónico', $invoice->billTypeLabel());
    }

    public function test_marcar_factura_exige_la_identificacion(): void
    {
        $this->formulario()
            ->set('wantsInvoice', true)
            ->call('save')
            ->assertHasErrors('recipient_identification')
            ->assertSee('hace falta la identificación del receptor');

        $this->assertSame(0, Invoice::count());
    }

    public function test_una_factura_con_identificacion_se_guarda_como_invoice(): void
    {
        $this->formulario()
            ->set('wantsInvoice', true)
            ->set('recipient_identification_type', '01')
            ->set('recipient_identification', '112340567')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(Invoice::BILL_INVOICE, $invoice->bill_type);
        $this->assertTrue($invoice->receptorIdentificado());
        $this->assertSame('112340567', $invoice->recipient_identification);
    }

    public function test_la_identificacion_se_guarda_sin_guiones(): void
    {
        $this->formulario()
            ->set('wantsInvoice', true)
            ->set('recipient_identification', '1-1234-0567')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('112340567', Invoice::firstOrFail()->recipient_identification);
    }

    public function test_una_identificacion_muy_corta_se_rechaza(): void
    {
        $this->formulario()
            ->set('wantsInvoice', true)
            ->set('recipient_identification', '123')
            ->call('save')
            ->assertHasErrors('recipient_identification');
    }

    public function test_apagar_el_toggle_limpia_la_identificacion(): void
    {
        $this->formulario()
            ->set('wantsInvoice', true)
            ->set('recipient_identification', '112340567')
            ->set('wantsInvoice', false)
            ->assertSet('recipient_identification', '')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(Invoice::BILL_TICKET, $invoice->bill_type);
        $this->assertNull($invoice->recipient_identification);
    }

    /** Una identificación suelta sin marcar factura no debe convertirlo en FE. */
    public function test_la_identificacion_sola_no_convierte_en_factura(): void
    {
        $invoice = Invoice::create([
            'code' => 'ENC-X', 'status' => Invoice::STATUS_PENDING,
            'bill_type' => Invoice::BILL_TICKET,
            'pickup_branch_id' => Branch::create(['name' => 'A', 'sucursal_code' => '003', 'terminal_code' => '00001', 'is_active' => true])->id,
            'delivery_branch_id' => Branch::create(['name' => 'B', 'sucursal_code' => '004', 'terminal_code' => '00001', 'is_active' => true])->id,
            'sender_name' => 'R', 'recipient_name' => 'S',
            'recipient_identification' => '112340567',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 130, 'total' => 1130,
            'created_by' => $this->admin()->id,
        ]);

        $this->assertFalse($invoice->receptorIdentificado());
    }

    public function test_al_editar_se_conserva_el_tipo_elegido(): void
    {
        $this->formulario()
            ->set('wantsInvoice', true)
            ->set('recipient_identification', '112340567')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = Invoice::firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(InvoiceForm::class, ['invoice' => $invoice])
            ->assertSet('wantsInvoice', true)
            ->assertSet('recipient_identification', '112340567');
    }
}
