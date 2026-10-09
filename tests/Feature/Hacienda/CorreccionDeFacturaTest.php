<?php

namespace Tests\Feature\Hacienda;

use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\Invoices\InvoiceShow;
use App\Models\Branch;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\CorreccionDeFactura;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use App\Services\Hacienda\TaxpayerLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Concerns\AbreLaCaja;
use Tests\TestCase;

/**
 * El administrador corrige a quién se factura una guía ya creada —un dato de
 * Hacienda mal digitado, o un tiquete al que después le pidieron factura— sin
 * tocar los montos. Y la cédula se autocompleta desde Hacienda.
 */
class CorreccionDeFacturaTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;
    use AbreLaCaja;

    private User $admin;

    private const DATOS = [
        'nombre' => 'Importadora Solano S.A.', 'tipo' => '02', 'numero' => '3101654321',
        'email' => 'facturas@solano.test', 'actividad' => '523101',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->companySettings();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'adm', 'email' => 'adm@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function tiquete(): Invoice
    {
        return $this->deliveredInvoice($this->branch(), [
            'bill_type' => Invoice::BILL_TICKET,
            'recipient_identification' => null,
        ]);
    }

    /** Hacienda «conoce» a esta cédula, sin salir a internet. */
    private function haciendaConoce(string $nombre, string $tipo, array $actividades): void
    {
        $this->app->instance(TaxpayerLookup::class, new class($nombre, $tipo, $actividades) extends TaxpayerLookup {
            public function __construct(private string $nombre, private string $tipo, private array $actividades) {}

            public function find(?string $identification): array
            {
                return ['status' => self::FOUND, 'name' => $this->nombre, 'id_type' => $this->tipo, 'activities' => $this->actividades];
            }
        });
    }

    // ── Corrección ────────────────────────────────────────────────────

    public function test_un_tiquete_pendiente_se_rehace_como_factura_con_clave_nueva(): void
    {
        $guia = $this->tiquete();
        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($guia);
        $claveVieja = $comprobante->clave;
        $this->assertSame('04', $comprobante->document_type);

        app(CorreccionDeFactura::class)->corregir($guia, $this->admin, self::DATOS);

        $comprobante->refresh();
        $this->assertSame('01', $comprobante->document_type);
        $this->assertNotSame($claveVieja, $comprobante->clave);
        $this->assertSame('3101654321', $comprobante->receptor_data['numero']);
        $this->assertSame(ElectronicInvoice::STATUS_PENDING, $comprobante->status);
        $this->assertEquals(11300, (float) $guia->fresh()->total, 'Los montos no cambian.');

        // Y el XML lleva la actividad económica del receptor.
        $xml = simplexml_load_string((new FacturaElectronicaXml($comprobante))->build());
        $this->assertSame('523101', (string) $xml->CodigoActividadReceptor);
        $this->assertSame('3101654321', (string) $xml->Receptor->Identificacion->Numero);
    }

    public function test_sin_comprobante_solo_cambia_los_datos_de_la_guia(): void
    {
        $guia = app(CorreccionDeFactura::class)->corregir($this->tiquete(), $this->admin, self::DATOS);

        $this->assertTrue($guia->receptorIdentificado());
        $this->assertSame('Importadora Solano S.A.', $guia->receptorDeFactura()['nombre']);
        $this->assertSame('523101', $guia->receptorDeFactura()['activity_code']);
    }

    /**
     * El caso que quedaba trabado: el tiquete ya fue aceptado y después el
     * cliente pidió factura. Se anula con nota de crédito y sale una factura
     * nueva, pendiente de envío.
     */
    public function test_un_tiquete_aceptado_se_anula_con_nota_y_sale_una_factura_nueva(): void
    {
        $guia = $this->tiquete();
        $tiquete = $this->markAccepted(app(ElectronicBillingService::class)->queueForInvoice($guia));

        app(CorreccionDeFactura::class)->corregir($guia, $this->admin, self::DATOS);

        $nota = ElectronicInvoice::where('reference_invoice_id', $tiquete->id)->sole();
        $this->assertSame('03', $nota->document_type);
        $this->assertEqualsWithDelta((float) $tiquete->total, (float) $nota->total, 0.001);
        $this->assertSame(0.0, $tiquete->fresh()->saldoSinAcreditar());

        $nueva = $guia->fresh()->electronicInvoice;
        $this->assertNotSame($tiquete->id, $nueva->id);
        $this->assertSame('01', $nueva->document_type);
        $this->assertSame(ElectronicInvoice::STATUS_PENDING, $nueva->status);
        $this->assertSame('3101654321', $nueva->receptor_data['numero']);
        $this->assertSame(ElectronicInvoice::STATUS_ACCEPTED, $tiquete->fresh()->status);
    }

    /** Si la nota ya se había emitido a mano, no se emite otra. */
    public function test_si_ya_estaba_acreditado_no_emite_otra_nota(): void
    {
        $guia = $this->tiquete();
        $servicio = app(ElectronicBillingService::class);
        $tiquete = $this->markAccepted($servicio->queueForInvoice($guia));
        $servicio->issueNote($tiquete, 'NC', 'Anulación manual del tiquete', (float) $tiquete->total);

        app(CorreccionDeFactura::class)->corregir($guia, $this->admin, self::DATOS);

        $this->assertSame(1, ElectronicInvoice::where('reference_invoice_id', $tiquete->id)->count());
        $this->assertSame('01', $guia->fresh()->electronicInvoice->document_type);
    }

    /** Anular y reemitir gasta dos consecutivos: no por guardar lo mismo. */
    public function test_un_aceptado_con_los_mismos_datos_no_se_reemite(): void
    {
        $guia = $this->tiquete();
        $this->markAccepted(app(ElectronicBillingService::class)->queueForInvoice($guia));

        try {
            app(CorreccionDeFactura::class)->corregir($guia, $this->admin, null);
            $this->fail('Debió rechazar la corrección sin cambios.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no hay nada que corregir', $e->getMessage());
        }

        $this->assertSame(1, ElectronicInvoice::where('invoice_id', $guia->id)->count());
    }

    public function test_mientras_hacienda_no_contesta_no_se_toca(): void
    {
        $guia = $this->tiquete();
        app(ElectronicBillingService::class)->queueForInvoice($guia)
            ->forceFill(['status' => ElectronicInvoice::STATUS_SENT])->save();

        $this->expectExceptionMessage('Esperá la respuesta');

        app(CorreccionDeFactura::class)->corregir($guia, $this->admin, self::DATOS);
    }

    public function test_desde_la_pantalla_con_el_tiquete_ya_aceptado(): void
    {
        $guia = $this->tiquete();
        $this->markAccepted(app(ElectronicBillingService::class)->queueForInvoice($guia));

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertSee('Corregir datos de facturación')
            ->call('openBillingForm')
            ->assertSee('se anula con una nota de crédito')
            ->set('quiereFactura', true)
            ->set('facturaTipoId', '02')
            ->set('facturaId', '3101654321')
            ->set('facturaNombre', 'Importadora Solano S.A.')
            ->call('guardarFacturacion')
            ->assertHasNoErrors()
            ->assertSee('Comprobantes reemplazados');

        $this->assertSame('01', $guia->fresh()->electronicInvoice->document_type);
    }

    public function test_solo_el_administrador(): void
    {
        $cajero = User::create(['name' => 'Caj', 'username' => 'caj', 'email' => 'caj@t.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->branch()->id]);

        $this->expectExceptionMessage('Solo un administrador');

        app(CorreccionDeFactura::class)->corregir($this->tiquete(), $cajero, self::DATOS);
    }

    public function test_desde_la_pantalla_de_la_guia(): void
    {
        $guia = $this->tiquete();

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertSee('Corregir datos de facturación')
            ->call('openBillingForm')
            ->set('quiereFactura', true)
            ->set('facturaTipoId', '02')
            ->set('facturaId', '3-101-654321')
            ->set('facturaNombre', 'Importadora Solano S.A.')
            ->call('guardarFacturacion')
            ->assertHasNoErrors();

        $this->assertSame('3101654321', $guia->fresh()->billing_identification);
    }

    /** Al corregir se puede elegir un cliente registrado y se copian sus datos. */
    public function test_se_puede_elegir_un_cliente_registrado(): void
    {
        $cliente = \App\Models\Customer::create([
            'name' => 'Importadora Solano S.A.', 'identification_type' => '02', 'identification' => '3101654321',
            'email' => 'facturas@solano.test', 'activity_code' => '523101', 'is_active' => true,
        ]);

        $pantalla = Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $this->tiquete()])
            ->call('openBillingForm')
            ->set('quiereFactura', true)
            ->set('facturaClienteBusqueda', 'Solano')
            ->assertSee('Importadora Solano S.A.')
            ->set('facturaClienteId', $cliente->id)
            ->assertSet('facturaNombre', 'Importadora Solano S.A.')
            ->assertSet('facturaTipoId', '02')
            ->assertSet('facturaId', '3101654321')
            ->assertSet('facturaEmail', 'facturas@solano.test')
            ->assertSet('facturaActividad', '523101')
            ->assertSet('facturaClienteBusqueda', '');

        $pantalla->call('guardarFacturacion')->assertHasNoErrors();
    }

    // ── Autocompletar desde Hacienda ──────────────────────────────────

    public function test_al_digitar_la_cedula_se_completan_nombre_tipo_y_actividad(): void
    {
        $this->haciendaConoce('IMPORTADORA SOLANO SOCIEDAD ANONIMA', '02', [
            ['code' => '523101', 'description' => 'Venta al por menor', 'principal' => true],
            ['code' => '492300', 'description' => 'Transporte de carga', 'principal' => false],
        ]);

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $this->tiquete()])
            ->call('openBillingForm')
            ->set('quiereFactura', true)
            ->set('facturaId', '3101654321')
            ->assertSet('facturaNombre', 'IMPORTADORA SOLANO SOCIEDAD ANONIMA')
            ->assertSet('facturaTipoId', '02')
            ->assertSet('facturaActividad', '523101')
            ->assertSee('Transporte de carga');
    }

    public function test_en_el_formulario_de_la_guia_una_juridica_se_autocompleta(): void
    {
        $this->haciendaConoce('IMPORTADORA SOLANO SOCIEDAD ANONIMA', '02', [
            ['code' => '523101', 'description' => 'Venta al por menor', 'principal' => true],
        ]);

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', true)
            ->set('bill_to', Invoice::BILL_TO_OTHER)
            ->set('billing_identification', '3-101-654321')
            ->assertSet('billing_name', 'IMPORTADORA SOLANO SOCIEDAD ANONIMA')
            ->assertSet('billing_identification_type', '02')
            ->assertSet('billing_activity_code', '523101');
    }

    /** El destinatario se autocompleta aunque la guía vaya como tiquete. */
    public function test_la_cedula_del_destinatario_completa_el_nombre_aunque_no_se_facture(): void
    {
        $this->haciendaConoce('JOSE FERNANDEZ MORA', '01', []);

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('recipient_identification', '1-1234-0567')
            ->assertSet('recipient_name', 'JOSE FERNANDEZ MORA')
            ->assertSet('recipient_identification', '112340567');
    }

    /** Un nombre de persona digitado a mano no se pisa; el que puso Hacienda sí. */
    public function test_no_pisa_un_nombre_digitado_a_mano(): void
    {
        $this->haciendaConoce('JOSE FERNANDEZ MORA', '01', []);

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('recipient_name', 'Pepe')
            ->set('recipient_identification', '112340567')
            ->assertSet('recipient_name', 'Pepe');
    }

    public function test_al_crear_un_cliente_la_cedula_completa_nombre_tipo_y_actividad(): void
    {
        $this->haciendaConoce('IMPORTADORA SOLANO SOCIEDAD ANONIMA', '02', [
            ['code' => '523101', 'description' => 'Venta al por menor', 'principal' => true],
        ]);

        Livewire::actingAs($this->admin)
            ->test(\App\Livewire\Customers\CustomerIndex::class)
            ->call('create')
            ->set('identification', '3-101-654321')
            ->assertSet('identification', '3101654321')
            ->assertSet('name', 'IMPORTADORA SOLANO SOCIEDAD ANONIMA')
            ->assertSet('identification_type', '02')
            ->assertSet('activity_code', '523101')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', ['identification' => '3101654321', 'name' => 'IMPORTADORA SOLANO SOCIEDAD ANONIMA']);
    }

    /** Si Hacienda no contesta, se avisa y se sigue a mano. */
    public function test_si_hacienda_no_contesta_no_traba_nada(): void
    {
        $this->app->instance(TaxpayerLookup::class, new class extends TaxpayerLookup {
            public function find(?string $identification): array
            {
                return ['status' => self::UNAVAILABLE];
            }
        });

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $this->tiquete()])
            ->call('openBillingForm')
            ->set('quiereFactura', true)
            ->set('facturaId', '3101654321')
            ->assertSee('No se pudo consultar Hacienda')
            ->set('facturaNombre', 'Importadora Solano S.A.')
            ->call('guardarFacturacion')
            ->assertHasNoErrors();
    }
}
