<?php

namespace Tests\Feature\Offline;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PackageType;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\AbreLaCaja;
use Tests\TestCase;

/**
 * Guías hechas sin conexión que se suben al volver el internet.
 *
 * Lo que se prueba es el contrato de sync(): una guía sincronizada queda igual
 * que una hecha en línea, nunca se duplica, y lo corregible se queda en la cola
 * (error) en vez de perderse (failed).
 */
class SincronizacionOfflineTest extends TestCase
{
    use RefreshDatabase;
    use AbreLaCaja;

    private Branch $sj;
    private Branch $lim;
    private User $cajero;
    private Tax $iva;
    private int $tipoBulto;

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
        $this->tipoBulto = PackageType::active()->firstOrFail()->id;
    }

    /** Una guía como la arma la pantalla offline: 10.000 + IVA. */
    private function guia(array $extra = []): array
    {
        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'offline_reference' => 'OFF-SJ-7',
            'created_at' => '2026-10-01T15:30:00.000Z',
            'sold_by' => $this->cajero->id,
            'pickup_branch_id' => $this->sj->id,
            'delivery_branch_id' => $this->lim->id,
            'shipment_type' => 'package',
            'sender_name' => 'Marta Solano',
            'recipient_name' => 'José Fernández',
            'recipient_phone' => '7777-2222',
            'home_delivery' => false,
            'cobro' => 'prepaid',
            'payment_method' => 'cash',
            'items' => [['package_type_id' => $this->tipoBulto, 'weight' => 2.5, 'price' => 10000]],
            'taxes' => [['id' => $this->iva->id, 'percent' => 13]],
            'subtotal' => 10000, 'insurance_fee' => 0, 'discount_amount' => 0,
            'tax_total' => 1300, 'total' => 11300,
        ], $extra);
    }

    private function subir(array ...$guias)
    {
        return $this->actingAs($this->cajero)->postJson(route('offline.sync'), ['guias' => $guias]);
    }

    public function test_una_guia_de_contado_queda_igual_que_una_en_linea(): void
    {
        $this->abrirCajaDe($this->sj, $this->cajero);

        $res = $this->subir($guia = $this->guia())->assertOk();

        $res->assertJsonPath('results.0.status', 'synced');

        $invoice = Invoice::where('client_uuid', $guia['client_uuid'])->firstOrFail();
        $this->assertSame('SJ-LIM-00001', $invoice->code);
        $res->assertJsonPath('results.0.code', 'SJ-LIM-00001');
        $res->assertJsonPath('results.0.etiqueta', route('invoices.etiqueta', $invoice));

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertSame('OFF-SJ-7', $invoice->offline_reference);
        $this->assertSame(Invoice::BILL_TICKET, $invoice->bill_type);
        $this->assertEquals(11300, (float) $invoice->total);
        $this->assertCount(1, $invoice->items);
        $this->assertEquals(1300, (float) $invoice->taxes->first()->amount);

        // El cobro entró al arqueo de quien lo cobró.
        $this->assertTrue(CashMovement::where('invoice_id', $invoice->id)->exists());

        // Nació cuando se recibió el paquete (UTC → zona de la app), no al sincronizar.
        $this->assertSame('2026-10-01 09:30', $invoice->created_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-01 09:30', $invoice->statusHistories->first()->happened_at->format('Y-m-d H:i'));
    }

    public function test_reintentar_no_duplica(): void
    {
        $this->abrirCajaDe($this->sj, $this->cajero);
        $guia = $this->guia();

        $this->subir($guia)->assertJsonPath('results.0.status', 'synced');
        $this->subir($guia)->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.0.code', 'SJ-LIM-00001');

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, CashMovement::count());
    }

    /** La plata está en la gaveta: sin caja abierta no hay arqueo donde entre. */
    public function test_contado_sin_caja_abierta_espera_en_la_cola(): void
    {
        $this->subir($this->guia())
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonFragment(['reason' => 'Cajera no tiene una caja abierta en la sede: abrila para que este cobro entre al arqueo y la guía se sube sola.']);

        $this->assertSame(0, Invoice::count());
    }

    public function test_por_cobrar_no_necesita_caja(): void
    {
        $this->subir($this->guia(['cobro' => 'collect']))->assertJsonPath('results.0.status', 'synced');

        $invoice = Invoice::firstOrFail();
        $this->assertTrue($invoice->tieneCobroPendiente());
        $this->assertSame(0, CashMovement::count());
    }

    /** Quien no cobra deja la guía esperando caja, igual que en línea. */
    public function test_quien_no_cobra_deja_la_guia_esperando_caja(): void
    {
        $this->cajero->forceFill(['can_collect' => false])->save();

        $this->subir($this->guia())->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.awaiting_cashier', true);

        $this->assertTrue(Invoice::firstOrFail()->esperandoCaja());
    }

    public function test_una_guia_que_no_cuadra_se_rechaza_para_siempre(): void
    {
        $this->abrirCajaDe($this->sj, $this->cajero);

        $this->subir($this->guia(['total' => 999]))
            ->assertJsonPath('results.0.status', 'failed')
            ->assertJsonPath('results.0.reason', 'El total no coincide con el desglose.');

        $this->assertSame(0, Invoice::count());
    }

    /** El precio cobrado manda aunque la tarifa haya cambiado: no se recotiza. */
    public function test_se_respeta_el_precio_cobrado(): void
    {
        $this->abrirCajaDe($this->sj, $this->cajero);

        $this->subir($this->guia([
            'items' => [['package_type_id' => $this->tipoBulto, 'weight' => 1, 'price' => 4000]],
            'subtotal' => 4000, 'tax_total' => 520, 'total' => 4520,
        ]))->assertJsonPath('results.0.status', 'synced');

        $this->assertEquals(4520, (float) Invoice::firstOrFail()->total);
    }

    /** Una mala no arrastra a las buenas de la misma tanda. */
    public function test_cada_guia_tiene_su_propio_resultado(): void
    {
        $this->abrirCajaDe($this->sj, $this->cajero);

        $this->subir($this->guia(), $this->guia(['sender_name' => '']), $this->guia(['cobro' => 'collect']))
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.1.status', 'failed')
            ->assertJsonPath('results.2.status', 'synced');

        $this->assertSame(2, Invoice::count());
    }

    public function test_un_impuesto_borrado_espera_en_la_cola(): void
    {
        $this->abrirCajaDe($this->sj, $this->cajero);
        $id = $this->iva->id;
        $this->iva->delete();

        $this->subir($this->guia(['taxes' => [['id' => $id, 'percent' => 13]]]))
            ->assertJsonPath('results.0.status', 'error');
    }

    public function test_credito_suma_al_saldo_del_cliente(): void
    {
        $cliente = Customer::create([
            'name' => 'Distribuidora Solano', 'identification_type' => '02', 'identification' => '3101123456',
            'payment_condition' => Customer::PAYMENT_CREDIT, 'credit_limit' => 50000, 'is_active' => true,
        ]);

        $this->subir($this->guia(['cobro' => 'credit', 'sender_customer_id' => $cliente->id]))
            ->assertJsonPath('results.0.status', 'synced');

        $invoice = Invoice::firstOrFail();
        $this->assertTrue($invoice->esCredito());
        $this->assertSame($cliente->id, $invoice->sender_customer_id);
        $this->assertSame(0, CashMovement::count());
    }

    /** Pasarse del límite es corregible (se le sube el límite): no se pierde. */
    public function test_credito_sobre_el_limite_espera_en_la_cola(): void
    {
        $cliente = Customer::create([
            'name' => 'Distribuidora Solano', 'payment_condition' => Customer::PAYMENT_CREDIT,
            'credit_limit' => 5000, 'is_active' => true,
        ]);

        $this->subir($this->guia(['cobro' => 'credit', 'sender_customer_id' => $cliente->id]))
            ->assertJsonPath('results.0.status', 'error');

        $this->assertSame(0, Invoice::count());
    }

    public function test_la_guia_queda_a_nombre_de_quien_la_recibio(): void
    {
        $otro = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
            'branch_id' => $this->sj->id, 'company_id' => $this->empresa->id,
        ]);

        $this->actingAs($otro)->postJson(route('offline.sync'), ['guias' => [$this->guia(['cobro' => 'collect'])]])
            ->assertJsonPath('results.0.status', 'synced');

        $this->assertSame($this->cajero->id, Invoice::firstOrFail()->created_by);
    }

    public function test_con_el_modo_apagado_no_se_sincroniza_nada(): void
    {
        CompanySetting::instance()->forceFill(['offline_mode' => false])->save();

        $this->subir($this->guia())->assertForbidden();
        $this->actingAs($this->cajero)->get(route('offline.page'))->assertRedirect(route('invoices.create'));
    }

    public function test_un_repartidor_no_tiene_modo_offline(): void
    {
        $repartidor = User::create([
            'name' => 'Chofer', 'username' => 'chofer', 'email' => 'chofer@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_REPARTIDOR, 'is_active' => true,
            'branch_id' => $this->sj->id, 'company_id' => $this->empresa->id,
        ]);

        $this->actingAs($repartidor)->getJson(route('offline.data'))->assertForbidden();
    }

    public function test_el_snapshot_lleva_lo_necesario_para_trabajar_sin_red(): void
    {
        CompanySetting::instance()->forceFill(['discount_authorization_code' => '4321'])->save();

        $snap = $this->actingAs($this->cajero)->getJson(route('offline.data'))->assertOk()->json();

        $this->assertTrue($snap['enabled']);
        $this->assertSame($this->sj->id, $snap['usuario']['branch_id']);
        $this->assertCount(2, $snap['sedes']);
        $this->assertSame(13.0, (float) $snap['impuestos'][0]['percent']);
        $this->assertNotEmpty($snap['tipos_bulto']);

        // La clave nunca viaja: viaja un verificador que el navegador recalcula.
        $this->assertStringNotContainsString('4321', json_encode($snap));
        $v = $snap['clave_descuento'];
        $this->assertSame(
            $v['hash'],
            bin2hex(hash_pbkdf2('sha256', '4321', hex2bin($v['salt']), $v['iterations'], 32, true))
        );
    }

    /** Cambiar la clave invalida el verificador viejo. */
    public function test_cambiar_la_clave_renueva_el_verificador(): void
    {
        $ajustes = CompanySetting::instance();
        $ajustes->forceFill(['discount_authorization_code' => '4321'])->save();
        $antes = $ajustes->verificadorDeDescuento();

        $ajustes->forceFill(['discount_authorization_code' => '9999'])->save();
        $despues = $ajustes->fresh()->verificadorDeDescuento();

        $this->assertNotSame($antes['hash'], $despues['hash']);
        $this->assertSame($despues, $ajustes->fresh()->verificadorDeDescuento(), 'Se guarda: no se recalcula en cada snapshot.');
    }

    /** La pantalla no puede depender de Livewire: la sirve el service worker sin red. */
    public function test_la_pantalla_offline_no_usa_livewire(): void
    {
        $html = $this->actingAs($this->cajero)->get(route('offline.page'))->assertOk()->getContent();

        $this->assertStringContainsString('/js/guias-offline.js', $html);
        $this->assertStringNotContainsString('livewire', strtolower($html));
    }

    public function test_el_watchdog_solo_se_arma_con_el_modo_encendido(): void
    {
        $this->actingAs($this->cajero)->get(route('dashboard'))
            ->assertSee("var HABILITADO = true", false)
            ->assertSee('data-offline-aviso', false);

        CompanySetting::instance()->forceFill(['offline_mode' => false])->save();

        $this->actingAs($this->cajero)->get(route('dashboard'))
            ->assertSee("var HABILITADO = false", false);
    }
}
