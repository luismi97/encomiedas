<?php

namespace Tests\Feature\Offline;

use App\Livewire\Invoices\InvoiceShow;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\PackageType;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\AbreLaCaja;
use Tests\TestCase;

/**
 * La guía provisional que se lleva el cliente atendido sin conexión.
 *
 * Ese cliente ya se fue del mostrador con un papel que dice P-SJ-7K3M9QX2. Al
 * volver el internet la guía se sube y recibe su código definitivo
 * (SJ-LIM-00001), pero el cliente no lo conoce: tiene que poder seguir
 * rastreando con el número que le dieron.
 */
class GuiaProvisionalTest extends TestCase
{
    use RefreshDatabase;
    use AbreLaCaja;

    private Branch $sj;
    private Branch $lim;
    private User $cajero;
    private Tax $iva;

    protected function setUp(): void
    {
        parent::setUp();

        CompanySetting::instance()->forceFill(['offline_mode' => true])->save();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);

        $this->cajero = User::create([
            'name' => 'Cajera', 'username' => 'cajera', 'email' => 'cajera@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
            'branch_id' => $this->sj->id, 'company_id' => $this->empresa->id,
        ]);

        $this->iva = Tax::create(['name' => 'IVA', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);
        $this->abrirCajaDe($this->sj, $this->cajero);
    }

    private function sincronizar(string $referencia): Invoice
    {
        $uuid = (string) Str::uuid();

        $this->actingAs($this->cajero)->postJson(route('offline.sync'), ['guias' => [[
            'client_uuid' => $uuid,
            'offline_reference' => $referencia,
            'created_at' => '2026-10-01T15:30:00.000Z',
            'sold_by' => $this->cajero->id,
            'pickup_branch_id' => $this->sj->id,
            'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta Solano',
            'recipient_name' => 'José Fernández',
            'home_delivery' => false,
            'cobro' => 'prepaid',
            'payment_method' => 'cash',
            'items' => [['package_type_id' => PackageType::active()->firstOrFail()->id, 'weight' => 2, 'price' => 10000]],
            'taxes' => [['id' => $this->iva->id, 'percent' => 13]],
            'subtotal' => 10000, 'insurance_fee' => 0, 'discount_amount' => 0,
            'tax_total' => 1300, 'total' => 11300,
        ]]])->assertJsonPath('results.0.status', 'synced');

        auth()->logout();

        return Invoice::where('client_uuid', $uuid)->firstOrFail();
    }

    public function test_se_rastrea_con_el_numero_provisional(): void
    {
        $guia = $this->sincronizar('P-SJ-7K3M9QX2');

        $this->get(route('rastreo.ver', 'P-SJ-7K3M9QX2'))
            ->assertOk()
            ->assertSee($guia->code)
            ->assertSee('Su comprobante provisional')
            ->assertSee('Recibido');
    }

    public function test_el_qr_del_comprobante_con_la_empresa_tambien_sirve(): void
    {
        $guia = $this->sincronizar('P-SJ-7K3M9QX2');

        $this->get(route('rastreo.empresa', ['empresa' => $this->empresa->slug, 'code' => 'P-SJ-7K3M9QX2']))
            ->assertOk()
            ->assertSee($guia->code);
    }

    /** Se dicta por teléfono o se copia del papel: minúsculas y espacios valen. */
    public function test_se_acepta_como_lo_escriba_el_cliente(): void
    {
        $guia = $this->sincronizar('P-SJ-7K3M9QX2');

        $this->get(route('rastreo.ver', 'p-sj-7k3m9qx2'))->assertOk()->assertSee($guia->code);
        $this->get(route('rastreo.buscar', ['codigo' => ' p sj 7k3m9qx2 ']))
            ->assertRedirect();
        $this->followingRedirects()
            ->get(route('rastreo.buscar', ['codigo' => 'p sj 7k3m9qx2']))
            ->assertSee($guia->code);
    }

    /** Todavía sin sincronizar: se explica en vez de «no existe». */
    public function test_antes_de_sincronizar_se_explica_que_falta(): void
    {
        $this->get(route('rastreo.ver', 'P-SJ-ABCDEFGH'))
            ->assertOk()
            ->assertSee('comprobante provisional')
            ->assertSee('no ha recuperado la conexión')
            ->assertDontSee('No encontramos');
    }

    public function test_un_codigo_comun_inexistente_sigue_diciendo_que_no_existe(): void
    {
        $this->get(route('rastreo.ver', 'SJ-LIM-99999'))
            ->assertOk()
            ->assertSee('No encontramos');
    }

    /** Las de la primera época (OFF-SJ-7) se repiten: no se muestra una al azar. */
    public function test_una_referencia_vieja_repetida_no_muestra_un_paquete_ajeno(): void
    {
        $this->sincronizar('OFF-SJ-7');
        $this->sincronizar('OFF-SJ-7');

        $this->get(route('rastreo.ver', 'OFF-SJ-7'))
            ->assertOk()
            ->assertSee('más de una encomienda')
            ->assertDontSee('Recorrido');
    }

    public function test_el_mostrador_la_encuentra_por_el_numero_provisional(): void
    {
        $guia = $this->sincronizar('P-SJ-7K3M9QX2');
        $this->sincronizar('P-SJ-QQQQQQQQ');

        $this->assertSame([$guia->id], Invoice::buscar('p-sj-7k3m9qx2')->pluck('id')->all());
    }

    public function test_el_detalle_muestra_el_numero_provisional(): void
    {
        $guia = $this->sincronizar('P-SJ-7K3M9QX2');

        Livewire::actingAs($this->cajero)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertSee('P-SJ-7K3M9QX2');
    }

    public function test_el_recibo_definitivo_recuerda_el_numero_provisional(): void
    {
        $guia = $this->sincronizar('P-SJ-7K3M9QX2');

        $this->actingAs($this->cajero)
            ->get(route('invoices.recibo', $guia))
            ->assertOk()
            ->assertSee('Comprobante provisional: P-SJ-7K3M9QX2');
    }

    /** El comprobante provisional imprime dónde rastrear. */
    public function test_el_snapshot_trae_la_direccion_de_rastreo(): void
    {
        $this->actingAs($this->cajero)
            ->getJson(route('offline.data'))
            ->assertOk()
            ->assertJsonPath('empresa.rastreo', route('rastreo.empresa', ['empresa' => $this->empresa->slug, 'code' => '__REF__']));
    }

    public function test_reconoce_las_dos_formas_de_referencia(): void
    {
        $this->assertSame('P-SJ-7K3M9QX2', Invoice::referenciaProvisional(' p-sj-7k3m9qx2 '));
        $this->assertSame('P-SJ-7K3M9QX2', Invoice::referenciaProvisional('P SJ 7K3M9QX2'));
        $this->assertSame('OFF-SJ-7', Invoice::referenciaProvisional('off-sj-7'));
        $this->assertNull(Invoice::referenciaProvisional('SJ-LIM-00001'));
        $this->assertNull(Invoice::referenciaProvisional('Marta'));
    }
}
