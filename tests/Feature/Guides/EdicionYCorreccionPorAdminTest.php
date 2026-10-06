<?php

namespace Tests\Feature\Guides;

use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\Invoices\InvoiceShow;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\CreditStatement;
use App\Models\Customer;
use App\Models\ElectronicInvoice;
use App\Models\GuideStatusHistory;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CreditoService;
use App\Services\GuideStatusService;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Hacienda\BuildsHaciendaFixtures;
use Tests\TestCase;

/**
 * El administrador puede editar una guía entera y corregir su estado cuando
 * alguien se equivocó.
 *
 * Editar todo no puede dejar colgado lo que se calculó con los datos viejos:
 * el estado de cuenta se recalcula y el comprobante pendiente se rehace.
 * Corregir el estado salta el ciclo normal, así que pide motivo, queda en la
 * bitácora y borra las marcas de lo que se deshace.
 */
class EdicionYCorreccionPorAdminTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private User $admin;
    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->companySettings();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'adm', 'email' => 'adm@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->cajero = User::create(['name' => 'Cajero', 'username' => 'caj', 'email' => 'caj@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->branch()->id]);
    }

    /** La guía de prueba con tipo de bulto, que el formulario exige al guardar. */
    private function guiaEditable(array $extra = []): Invoice
    {
        $guia = $this->deliveredInvoice($this->branch(), $extra);
        $guia->items()->update(['package_type_id' => \App\Models\PackageType::porDefecto()?->id]);

        return $guia->fresh(['items', 'taxes', 'pickupBranch']);
    }

    private function estados(): GuideStatusService
    {
        return app(GuideStatusService::class);
    }

    // ── Editar ────────────────────────────────────────────────────────

    public function test_el_admin_ve_el_boton_de_editar_y_el_cajero_no(): void
    {
        $guia = $this->deliveredInvoice($this->branch());

        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertSee('Editar guía')
            ->assertSee(route('invoices.edit', $guia), false);

        Livewire::actingAs($this->cajero)->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertDontSee('Editar guía');
    }

    public function test_el_admin_abre_la_edicion_de_una_guia_entregada(): void
    {
        $guia = $this->deliveredInvoice($this->branch());

        $this->actingAs($this->admin)->get(route('invoices.edit', $guia))
            ->assertOk()
            ->assertSee('Editando la guía ' . $guia->code);
    }

    public function test_editar_el_monto_de_una_guia_cortada_recalcula_el_estado_de_cuenta(): void
    {
        $cliente = Customer::create(['name' => 'Ferretería', 'payment_condition' => Customer::PAYMENT_CREDIT]);
        $guia = $this->deliveredInvoice($this->branch(), [
            'sale_condition' => Invoice::SALE_CREDIT,
            'sender_customer_id' => $cliente->id,
            'bill_type' => Invoice::BILL_TICKET,
        ]);
        $estado = app(CreditoService::class)->cortar($cliente, $this->admin);
        $this->assertSame('11300.00', (string) $estado->total);

        // Un solo bulto de 20 000 en vez de los dos de antes.
        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class, ['invoice' => $guia])
            ->set('items', [[
                'package_type_id' => \App\Models\PackageType::porDefecto()?->id, 'quantity' => 1, 'size' => 'M',
                'weight' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'description' => 'Caja', 'price' => 20000,
            ]])
            ->set('selectedTaxes', [])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('20000.00', (string) $estado->fresh()->total);
        $this->assertSame('20000.00', (string) $estado->fresh()->balance);
        $this->assertSame($estado->id, $guia->fresh()->credit_statement_id);
    }

    public function test_si_deja_de_ser_a_credito_sale_del_estado_de_cuenta(): void
    {
        $cliente = Customer::create(['name' => 'Ferretería', 'payment_condition' => Customer::PAYMENT_CREDIT]);
        $guia = $this->guiaEditable([
            'sale_condition' => Invoice::SALE_CREDIT,
            'sender_customer_id' => $cliente->id,
            'bill_type' => Invoice::BILL_TICKET,
            'payment_timing' => Invoice::TIMING_COLLECT,
        ]);
        $estado = app(CreditoService::class)->cortar($cliente, $this->admin);

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class, ['invoice' => $guia])
            ->set('cobro', InvoiceForm::COBRO_COLLECT)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($guia->fresh()->credit_statement_id);
        $this->assertSame('0.00', (string) $estado->fresh()->total);
    }

    /** Cambiar a quién se factura rehace el comprobante que no salió todavía. */
    public function test_cambiar_la_facturacion_rehace_el_comprobante_pendiente(): void
    {
        $guia = $this->guiaEditable();
        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($guia);
        $this->assertSame('112340567', $comprobante->receptor_data['numero']);

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class, ['invoice' => $guia])
            ->set('recipient_identification', '3101999888')
            ->set('recipient_identification_type', '02')
            ->set('recipient_name', 'Empresa Receptora S.A.')
            ->call('save')
            ->assertHasNoErrors();

        $comprobante->refresh();
        $this->assertSame('3101999888', $comprobante->receptor_data['numero']);
        $this->assertSame(ElectronicInvoice::STATUS_PENDING, $comprobante->status);
    }

    /** Uno aceptado no se toca: se avisa que corresponde una nota. */
    public function test_con_comprobante_aceptado_avisa_que_hace_falta_una_nota(): void
    {
        $guia = $this->guiaEditable();
        $comprobante = $this->markAccepted(app(ElectronicBillingService::class)->queueForInvoice($guia));
        $clave = $comprobante->clave;

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class, ['invoice' => $guia])
            ->set('recipient_name', 'Otro Nombre')
            ->set('recipient_identification', '3101999888')
            ->set('recipient_identification_type', '02')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($clave, $comprobante->fresh()->clave);
        $this->assertStringContainsString('nota de crédito o débito', (string) session('info'));
    }

    // ── Corregir el estado ────────────────────────────────────────────

    public function test_el_admin_quita_un_entregado_puesto_por_error(): void
    {
        $guia = $this->deliveredInvoice($this->branch(), [
            'received_by_name' => 'Fulano', 'received_by_identification' => '111111111',
            'arrived_at' => now()->subDay(),
        ]);

        $corregida = $this->estados()->corregirEstado($guia, Invoice::STATUS_AT_DESTINATION, $this->admin, 'Se marcó la guía equivocada');

        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $corregida->status);
        $this->assertNull($corregida->delivered_at);
        $this->assertNull($corregida->received_by_name);
        $this->assertNotNull($corregida->arrived_at, 'Sigue en destino: la llegada fue real.');

        $paso = GuideStatusHistory::where('invoice_id', $guia->id)->latest('id')->first();
        $this->assertSame(Invoice::STATUS_DELIVERED, $paso->from_status);
        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $paso->to_status);
        $this->assertStringContainsString('Se marcó la guía equivocada', $paso->note);
        $this->assertSame($this->admin->id, $paso->user_id);
    }

    public function test_puede_volver_hasta_recibido_y_borra_la_llegada(): void
    {
        $guia = $this->deliveredInvoice($this->branch(), [
            'status' => Invoice::STATUS_AT_DESTINATION, 'delivered_at' => null, 'arrived_at' => now(),
        ]);

        $corregida = $this->estados()->corregirEstado($guia, Invoice::STATUS_PENDING, $this->admin, 'Nunca salió de la bodega');

        $this->assertSame(Invoice::STATUS_PENDING, $corregida->status);
        $this->assertNull($corregida->arrived_at);
    }

    public function test_puede_adelantar_un_estado_que_no_se_marco(): void
    {
        $guia = $this->deliveredInvoice($this->branch(), ['status' => Invoice::STATUS_PENDING, 'delivered_at' => null]);

        $corregida = $this->estados()->corregirEstado($guia, Invoice::STATUS_AT_DESTINATION, $this->admin, 'Llegó y no se escaneó');

        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $corregida->status);
        $this->assertNotNull($corregida->arrived_at);
    }

    public function test_revive_una_anulada_sin_nota_de_credito(): void
    {
        $guia = $this->deliveredInvoice($this->branch(), ['status' => Invoice::STATUS_PENDING, 'delivered_at' => null]);
        $this->estados()->anular($guia, $this->admin, 'Por error');

        $corregida = $this->estados()->corregirEstado($guia->fresh(), Invoice::STATUS_PENDING, $this->admin, 'Se anuló la equivocada');

        $this->assertSame(Invoice::STATUS_PENDING, $corregida->status);
        $this->assertNull($corregida->cancellation_reason);
        $this->assertNull($corregida->cancelled_at);
    }

    public function test_no_revive_una_anulada_con_nota_de_credito(): void
    {
        $guia = $this->deliveredInvoice($this->branch());
        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($guia);
        $comprobante->forceFill(['status' => ElectronicInvoice::STATUS_ACCEPTED, 'total' => 11300])->save();
        $this->estados()->anular($guia, $this->admin, 'Cobro duplicado');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nota de crédito');

        $this->estados()->corregirEstado($guia->fresh(), Invoice::STATUS_DELIVERED, $this->admin, 'Revivir');
    }

    public function test_para_anular_hay_que_usar_anular(): void
    {
        $guia = $this->deliveredInvoice($this->branch());

        $this->expectExceptionMessage('Anular');

        $this->estados()->corregirEstado($guia, Invoice::STATUS_CANCELLED, $this->admin, 'x');
    }

    /** Un por cobrar no se da por entregado sin cobrar: eso es una entrega. */
    public function test_no_pasa_a_entregado_una_guia_con_cobro_pendiente(): void
    {
        $guia = $this->deliveredInvoice($this->branch(), [
            'status' => Invoice::STATUS_AT_DESTINATION, 'delivered_at' => null,
            'payment_timing' => Invoice::TIMING_COLLECT,
        ]);
        $this->assertTrue($guia->tieneCobroPendiente());

        $this->expectExceptionMessage('cobro pendiente');

        $this->estados()->corregirEstado($guia, Invoice::STATUS_DELIVERED, $this->admin, 'Ya se entregó');
    }

    public function test_sin_motivo_no_se_corrige(): void
    {
        $guia = $this->deliveredInvoice($this->branch());

        $this->expectExceptionMessage('motivo');

        $this->estados()->corregirEstado($guia, Invoice::STATUS_AT_DESTINATION, $this->admin, '   ');
    }

    public function test_un_cajero_no_corrige_estados(): void
    {
        $guia = $this->deliveredInvoice($this->branch());

        $this->expectExceptionMessage(GuideStatusService::SOLO_ADMIN_CORRIGE);

        $this->estados()->corregirEstado($guia, Invoice::STATUS_AT_DESTINATION, $this->cajero, 'Error');
    }

    public function test_desde_la_guia_queda_en_la_bitacora(): void
    {
        $guia = $this->deliveredInvoice($this->branch());

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertSee('Corregir estado')
            ->call('openStatusFixForm')
            ->set('statusFixTo', Invoice::STATUS_AT_DESTINATION)
            ->set('statusFixReason', 'Entregada por error')
            ->call('corregirEstado')
            ->assertSet('showStatusFixForm', false);

        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $guia->fresh()->status);
        $this->assertTrue(ActivityLog::where('action', 'status_corrected')->where('invoice_id', $guia->id)->exists());
    }

    public function test_el_cajero_no_ve_corregir_estado(): void
    {
        $guia = $this->deliveredInvoice($this->branch());

        Livewire::actingAs($this->cajero)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertDontSee('Corregir estado')
            ->call('openStatusFixForm')
            ->assertSet('showStatusFixForm', false);
    }
}
