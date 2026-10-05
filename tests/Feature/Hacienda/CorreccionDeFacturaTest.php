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

    public function test_un_comprobante_aceptado_no_se_toca(): void
    {
        $guia = $this->tiquete();
        app(ElectronicBillingService::class)->queueForInvoice($guia)
            ->forceFill(['status' => ElectronicInvoice::STATUS_ACCEPTED])->save();

        $this->expectExceptionMessage('nota de crédito');

        app(CorreccionDeFactura::class)->corregir($guia, $this->admin, self::DATOS);
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
