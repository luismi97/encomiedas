<?php

namespace Tests\Feature\Invoices;

use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\Hacienda\ExoneracionLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\AbreLaCaja;
use Tests\TestCase;

/**
 * Al cliente exonerado no se le cobra el IVA, y no hay que acordarse: su guía
 * se marca sola, sale como Factura Electrónica a su nombre y guarda la
 * exoneración que se declaró.
 */
class ExoneracionEnGuiaTest extends TestCase
{
    use RefreshDatabase;
    use AbreLaCaja;

    private Branch $sj;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@t.test'],
            ['name' => 'Admin', 'username' => 'admin', 'password' => bcrypt('x'),
             'role' => User::ROLE_ADMIN, 'is_active' => true]
        );
    }

    private function exonerado(array $cambios = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Zona Franca Coyol S.A.', 'identification_type' => '02', 'identification' => '3101878072',
            'email' => 'cxp@coyol.test', 'payment_condition' => Customer::PAYMENT_CASH, 'is_active' => true,
            'tax_exempt' => true, 'exemption_document_type' => '08', 'exemption_number' => 'AL-00012345-25',
            'exemption_institution' => '01', 'exemption_issued_at' => '2025-01-02',
            'exemption_expires_at' => now()->addYear()->toDateString(), 'exemption_rate' => 13,
        ], $cambios));
    }

    private function formulario()
    {
        $this->sj = Branch::create(['name' => 'San José', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $al = Branch::create(['name' => 'Alajuela', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        Tax::create(['name' => 'IVA general', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);
        $this->abrirCajaDe($this->sj, $this->admin());

        return Livewire::actingAs($this->admin())
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $al->id)
            ->set('recipient_name', 'José Fernández')
            ->set('items.0.price', 10000);
    }

    public function test_elegir_un_remitente_exonerado_marca_la_guia_sola(): void
    {
        $cliente = $this->exonerado();

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->assertSet('tax_exempt', true)
            ->assertSet('wantsInvoice', true)
            ->assertSet('bill_to', Invoice::BILL_TO_SENDER)
            ->assertSeeHtml('data-test="exoneracion-aplicada"')
            ->assertSee('AL-00012345-25')
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::firstOrFail();
        $this->assertTrue($guia->tax_exempt);
        $this->assertEqualsWithDelta(0.0, (float) $guia->tax_total, 0.001);
        $this->assertEqualsWithDelta(1300.0, (float) $guia->exempt_tax_amount, 0.001);
        $this->assertEqualsWithDelta(10000.0, (float) $guia->total, 0.001);
        $this->assertSame('AL-00012345-25', $guia->exoneracion()['numero']);
        $this->assertSame('3101878072', $guia->exoneracion()['identificacion']);
        // El IVA a la tarifa queda en el desglose; lo exonerado, aparte.
        $this->assertEqualsWithDelta(1300.0, (float) $guia->taxes->first()->amount, 0.001);
    }

    public function test_tambien_al_elegirlo_como_destinatario(): void
    {
        $cliente = $this->exonerado();

        $this->formulario()
            ->set('sender_name', 'Marta Solano')
            ->set('recipient_customer_id', $cliente->id)
            ->assertSet('tax_exempt', true)
            ->assertSet('bill_to', Invoice::BILL_TO_RECIPIENT)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(10000.0, (float) Invoice::firstOrFail()->total, 0.001);
    }

    public function test_un_cliente_comun_paga_el_iva(): void
    {
        $cliente = Customer::create(['name' => 'Marta Solano', 'identification_type' => '01',
            'identification' => '112340567', 'payment_condition' => Customer::PAYMENT_CASH, 'is_active' => true]);

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->assertSet('tax_exempt', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(11300.0, (float) Invoice::firstOrFail()->total, 0.001);
    }

    /** Facturarle a otro le cobra el IVA: la exoneración es del exonerado. */
    public function test_cambiar_a_quien_se_factura_quita_la_exoneracion(): void
    {
        $cliente = $this->exonerado();

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->set('recipient_identification', '112340567')
            ->set('bill_to', Invoice::BILL_TO_RECIPIENT)
            ->assertSet('tax_exempt', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(11300.0, (float) Invoice::firstOrFail()->total, 0.001);
    }

    public function test_marcarla_a_mano_sin_exoneracion_registrada_no_se_guarda(): void
    {
        $this->formulario()
            ->set('sender_name', 'Marta Solano')
            ->set('wantsInvoice', true)
            ->set('recipient_identification', '112340567')
            ->set('tax_exempt', true)
            ->call('save')
            ->assertHasErrors('tax_exempt');

        $this->assertSame(0, Invoice::count());
    }

    public function test_una_exoneracion_vencida_no_se_declara(): void
    {
        $cliente = $this->exonerado(['exemption_expires_at' => now()->subDay()->toDateString()]);

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->call('save')
            ->assertHasErrors('tax_exempt')
            ->assertSee('venció');
    }

    /** Si EXONET la limita a otros CABYS, Hacienda la rechazaría. */
    public function test_una_exoneracion_que_no_cubre_el_cabys_no_se_declara(): void
    {
        $cliente = $this->exonerado(['exemption_cabys' => ['7211200000100']]);

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->call('save')
            ->assertHasErrors('tax_exempt')
            ->assertSee('no cubre el CABYS');
    }

    // ── Clientes ──────────────────────────────────────────────────────

    public function test_el_cliente_se_registra_exonerado(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->call('create')
            ->set('name', 'Zona Franca Coyol S.A.')
            ->set('identification', '3101878072')
            ->set('tax_exempt', true)
            ->set('exemption_number', 'al-00012345-25')
            ->set('exemption_document_type', '08')
            ->set('exemption_institution', '01')
            ->set('exemption_issued_at', '2025-01-02')
            ->set('exemption_rate', 13)
            ->call('save')
            ->assertHasNoErrors();

        $cliente = Customer::firstOrFail();
        $this->assertTrue($cliente->estaExonerado());
        $this->assertSame('AL-00012345-25', $cliente->exemption_number);
    }

    public function test_exonerado_exige_identificacion_y_numero(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->call('create')
            ->set('name', 'Sin cédula')
            ->set('tax_exempt', true)
            ->call('save')
            ->assertHasErrors('identification');

        Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->call('create')
            ->set('name', 'Sin número')
            ->set('identification', '3101878072')
            ->set('tax_exempt', true)
            ->call('save')
            ->assertHasErrors(['exemption_number', 'exemption_document_type', 'exemption_institution', 'exemption_issued_at']);
    }

    public function test_desmarcar_la_exoneracion_borra_sus_datos(): void
    {
        $cliente = $this->exonerado();

        Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->call('edit', $cliente->id)
            ->set('tax_exempt', false)
            ->call('save')
            ->assertHasNoErrors();

        $cliente->refresh();
        $this->assertFalse($cliente->estaExonerado());
        $this->assertNull($cliente->exemption_number);
    }

    public function test_consultar_en_hacienda_completa_la_exoneracion(): void
    {
        $this->app->instance(ExoneracionLookup::class, new class extends ExoneracionLookup {
            public function find(?string $numero): array
            {
                return [
                    'status' => self::FOUND, 'numero' => 'AL-00012345-25', 'identificacion' => '3101878072',
                    'tipo' => '04', 'institucion' => '01', 'fecha_emision' => '2025-01-02',
                    'vence' => '2030-01-02', 'tarifa' => 13.0, 'cabys' => ['8511200000000'],
                ];
            }
        });

        Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->call('create')
            ->set('name', 'Zona Franca Coyol S.A.')
            ->set('tax_exempt', true)
            ->set('exemption_number', 'AL-00012345-25')
            ->call('consultarExoneracion')
            ->assertSet('identification', '3101878072')
            ->assertSet('exemption_document_type', '04')
            ->assertSet('exemption_institution', '01')
            ->assertSet('exemption_issued_at', '2025-01-02')
            ->assertSet('exemption_expires_at', '2030-01-02')
            ->assertSet('exemption_cabys', ['8511200000000'])
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_avisa_si_la_exoneracion_es_de_otra_cedula(): void
    {
        $this->app->instance(ExoneracionLookup::class, new class extends ExoneracionLookup {
            public function find(?string $numero): array
            {
                return ['status' => self::FOUND, 'numero' => 'AL-1', 'identificacion' => '3101999999',
                    'tipo' => '04', 'institucion' => '01', 'fecha_emision' => '2025-01-02',
                    'vence' => null, 'tarifa' => 13.0, 'cabys' => []];
            }
        });

        Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->call('create')
            ->set('identification', '3101878072')
            ->set('tax_exempt', true)
            ->set('exemption_number', 'AL-1')
            ->call('consultarExoneracion')
            ->assertSeeHtml('data-test="aviso-exoneracion"')
            ->assertSee('3101999999');
    }

    /** Lo que devuelve EXONET, tal como vino de la API real. */
    public function test_el_lookup_interpreta_la_respuesta_de_exonet(): void
    {
        config(['hacienda.exoneracion_url' => 'https://exonet.test/fe/ex']);
        Http::fake(['exonet.test/*' => Http::response([
            'numeroDocumento' => 'AL-00000001-25', 'identificacion' => '3102878072',
            'porcentajeExoneracion' => 13, 'fechaEmision' => '2025-01-02T00:00:00',
            'fechaVencimiento' => '2026-01-02T00:00:00', 'cabys' => ['7211200000100'],
            'tipoDocumento' => ['codigo' => '04', 'descripcion' => 'Exenciones Dirección General de Hacienda'],
            'CodigoInstitucion' => '01', 'nombreInstitucion' => 'Ministerio de Hacienda', 'poseeCabys' => true,
        ])]);

        $r = app(ExoneracionLookup::class)->find('al-00000001-25');

        $this->assertSame(ExoneracionLookup::FOUND, $r['status']);
        $this->assertSame('3102878072', $r['identificacion']);
        $this->assertSame('04', $r['tipo']);
        $this->assertSame('01', $r['institucion']);
        $this->assertSame('2025-01-02', $r['fecha_emision']);
        $this->assertSame('2026-01-02', $r['vence']);
        $this->assertSame(13.0, $r['tarifa']);
        $this->assertSame(['7211200000100'], $r['cabys']);
    }

    public function test_un_numero_que_exonet_no_conoce(): void
    {
        $this->assertSame(ExoneracionLookup::NOT_FOUND, app(ExoneracionLookup::class)->find('AL-99999999-25')['status']);
    }
}
