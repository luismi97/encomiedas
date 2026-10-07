<?php

namespace Tests\Feature\Hacienda;

use App\Jobs\SendElectronicInvoiceJob;
use App\Livewire\Hacienda\PendingQueue;
use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\Invoices\InvoiceShow;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\GuideStatusService;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\AbreLaCaja;
use Tests\TestCase;

/**
 * El comprobante se reserva al recibir el paquete, para que el recibo del
 * cliente salga con su consecutivo. La clave definitiva —lleva la fecha de
 * emisión— se completa al enviarlo.
 *
 * Solo se reserva: el envío a Hacienda lo sigue decidiendo el administrador.
 * Y como ahora el comprobante existe desde el principio, lo que antes se
 * resolvía antes de crearlo —pedir factura con cédula al retirar, cambiar la
 * sede de origen— tiene que rehacerlo mientras no haya salido.
 */
class ClaveDesdeLaRecepcionTest extends TestCase
{
    use RefreshDatabase;
    use AbreLaCaja;

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);
        Tax::create(['name' => 'IVA', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);

        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'a@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->abrirCajaDe($this->sj, $this->admin);
    }

    private function facturacionLista(): void
    {
        $s = CompanySetting::instance();
        $s->enabled = true;
        $s->identification_number = '3101234567';
        $s->identification_type = '02';
        $s->certificate_path = 'hacienda/certificados/prueba.p12';
        $s->certificate_pin = '1234';
        $s->atv_username = 'usuario@atv';
        $s->atv_password = 'clave';
        $s->save();
    }

    private function recibir(array $extra = []): Invoice
    {
        $form = Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta Solano')
            ->set('recipient_name', 'José Fernández')
            ->set('items.0.price', 5000);

        foreach ($extra as $campo => $valor) {
            $form->set($campo, $valor);
        }

        $form->call('save')->assertHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    // ── La reserva ────────────────────────────────────────────────────

    /**
     * La clave todavía no: lleva la fecha de emisión, que es la del envío
     * (ver FechaDeEmisionAlEnviarTest).
     */
    public function test_al_recibir_se_reserva_el_comprobante_y_el_recibo_lleva_el_consecutivo(): void
    {
        $this->facturacionLista();

        $guia = $this->recibir();
        $comprobante = $guia->electronicInvoice;

        $this->assertNotNull($comprobante, 'El consecutivo se reserva al recibir el paquete.');
        $this->assertSame(ElectronicInvoice::STATUS_PENDING, $comprobante->status);
        $this->assertSame('04', $comprobante->document_type, 'Sin cédula: tiquete.');

        $this->actingAs($this->admin)
            ->get(route('invoices.recibo', $guia))
            ->assertSee($comprobante->consecutivo)
            ->assertDontSee('Clave numérica')
            ->assertDontSee($comprobante->clave);
    }

    /** Reservar no es enviar: eso lo sigue decidiendo el administrador. */
    public function test_reservar_no_envia_a_hacienda(): void
    {
        $this->facturacionLista();

        $this->recibir();

        Bus::assertNotDispatched(SendElectronicInvoiceJob::class);
    }

    public function test_con_cedula_se_reserva_como_factura_electronica(): void
    {
        $this->facturacionLista();

        $guia = $this->recibir([
            'wantsInvoice' => true,
            'recipient_identification_type' => '01',
            'recipient_identification' => '112340567',
        ]);

        $this->assertSame('01', $guia->electronicInvoice->document_type);
        $this->assertSame('112340567', $guia->electronicInvoice->receptor_data['numero']);
    }

    /** Al entregar no se crea otro: se usa el reservado. */
    public function test_entregar_no_crea_un_segundo_comprobante(): void
    {
        $this->facturacionLista();
        $guia = $this->recibir();

        $estados = app(GuideStatusService::class);
        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $estado) {
            $guia = $estados->cambiar($guia, $estado, $this->admin);
        }
        $estados->entregar($guia, $this->admin, 'José');

        $this->assertSame(1, ElectronicInvoice::where('invoice_id', $guia->id)->count());
    }

    public function test_sin_facturacion_configurada_la_guia_se_crea_sin_clave(): void
    {
        $guia = $this->recibir();

        $this->assertNull($guia->electronicInvoice);
        $this->actingAs($this->admin)->get(route('invoices.recibo', $guia))->assertDontSee('Clave numérica');
    }

    /** El paquete ya se recibió: un fallo al reservar no puede perder la guía. */
    public function test_si_la_reserva_falla_la_guia_igual_se_crea(): void
    {
        $this->facturacionLista();
        $this->mock(ElectronicBillingService::class)
            ->shouldReceive('queueForInvoice')->andThrow(new RuntimeException('Hacienda caída'));

        $guia = $this->recibir();

        $this->assertSame('José Fernández', $guia->recipient_name);
        $this->assertNull($guia->electronicInvoice);
    }

    // ── Lo que ahora tiene que rehacerlo ──────────────────────────────

    public function test_pedir_factura_al_retirar_rehace_el_comprobante_reservado(): void
    {
        $this->facturacionLista();
        $guia = $this->recibir();
        $claveDelTiquete = $guia->electronicInvoice->clave;

        $estados = app(GuideStatusService::class);
        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $estado) {
            $guia = $estados->cambiar($guia, $estado, $this->admin);
        }

        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('openDeliveryForm')
            ->assertSee('¿Quiere factura con cédula?')
            ->set('receivedByName', 'Ana Mora')
            ->set('quiereFactura', true)
            ->set('facturaNombre', 'Ana Mora')
            ->set('facturaTipoId', '01')
            ->set('facturaId', '112340567')
            ->call('entregar')
            ->assertHasNoErrors();

        $comprobante = $guia->fresh()->electronicInvoice;
        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->fresh()->status);
        $this->assertSame('01', $comprobante->document_type);
        $this->assertSame('112340567', $comprobante->receptor_data['numero']);
        $this->assertNotSame($claveDelTiquete, $comprobante->clave, 'FE y TE llevan claves distintas.');
        $this->assertSame(1, ElectronicInvoice::where('invoice_id', $guia->id)->count());
    }

    public function test_con_el_comprobante_ya_en_hacienda_no_se_ofrece_factura_al_retirar(): void
    {
        $this->facturacionLista();
        $guia = $this->recibir();
        $guia->electronicInvoice->forceFill(['status' => ElectronicInvoice::STATUS_ACCEPTED])->save();

        $estados = app(GuideStatusService::class);
        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $estado) {
            $guia = $estados->cambiar($guia, $estado, $this->admin);
        }

        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('openDeliveryForm')
            ->assertDontSee('¿Quiere factura con cédula?');

        $this->expectExceptionMessage('enviado a Hacienda');
        $estados->entregar($guia, $this->admin, 'Ana', null, null,
            ['nombre' => 'Ana', 'tipo' => '01', 'numero' => '112340567', 'email' => null, 'actividad' => null]);
    }

    /** La sede de origen va dentro de la clave. */
    public function test_cambiar_la_sede_de_origen_rehace_la_clave(): void
    {
        $this->facturacionLista();
        $guia = $this->recibir();
        $this->assertSame('001', substr($guia->electronicInvoice->consecutivo, 0, 3));

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class, ['invoice' => $guia])
            ->set('pickup_branch_id', $this->lim->id)
            ->set('delivery_branch_id', $this->sj->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('006', substr($guia->fresh()->electronicInvoice->consecutivo, 0, 3));
        $this->assertStringContainsString('reimprimí el recibo', (string) session('info'));
    }

    // ── La lista de pendientes ────────────────────────────────────────

    public function test_la_lista_de_pendientes_no_muestra_guias_anuladas(): void
    {
        $this->facturacionLista();
        $viva = $this->recibir();
        $anulada = $this->recibir(['recipient_name' => 'Pedro Anulado']);
        app(GuideStatusService::class)->anular($anulada, $this->admin, 'Se equivocó de destino');

        Livewire::actingAs($this->admin)->test(PendingQueue::class)
            ->assertSee($viva->code)
            ->assertDontSee($anulada->code)
            ->assertSee('Recibido', false);
    }
}
