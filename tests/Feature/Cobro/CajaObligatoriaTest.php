<?php

namespace Tests\Feature\Cobro;

use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * No se cobra de contado sin una caja abierta.
 *
 * La guía se guardaba igual y solo se dejaba un aviso en una propiedad del
 * componente, que el redirect posterior descartaba: nadie lo veía nunca. El
 * resultado era plata cobrada en el mostrador que no figuraba en ningún arqueo
 * —justo lo que este módulo existe para impedir—.
 */
class CajaObligatoriaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        Tax::create(['name'=>'IVA','percent'=>13,'hacienda_code'=>'08','is_default'=>true,'is_active'=>true]);

        $this->cajero = User::create(['name'=>'Ana','username'=>'ana','email'=>'ana@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$this->sj->id]);
    }

    private function abrirCaja()
    {
        return app(CajaService::class)->abrir($this->sj->cashRegisters()->firstOrFail(), $this->cajero, 10000);
    }

    private function formulario()
    {
        return Livewire::actingAs($this->cajero)
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.price', 10000);
    }

    // ── Lo reportado ──────────────────────────────────────────────────

    public function test_sin_caja_abierta_no_se_puede_cobrar_de_contado(): void
    {
        $this->formulario()
            ->set('cobro', 'prepaid')
            ->call('save')
            ->assertHasErrors('cobro')
            ->assertSee('No tenés una caja abierta');

        $this->assertSame(0, Invoice::count(), 'La guía no puede quedar registrada.');
    }

    public function test_el_mensaje_dice_como_salir_del_paso(): void
    {
        $this->formulario()
            ->set('cobro', 'prepaid')
            ->call('save')
            ->assertSee('Abrí tu caja')
            ->assertSee('Por cobrar');
    }

    public function test_con_la_caja_abierta_la_venta_pasa(): void
    {
        $sesion = $this->abrirCaja();

        $this->formulario()->set('cobro', 'prepaid')->call('save')->assertHasNoErrors();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, CashMovement::where('cash_session_id', $sesion->id)->count());
    }

    /** Abrir la caja y reintentar: los datos del formulario no se pierden. */
    public function test_tras_abrir_la_caja_se_guarda_sin_redigitar(): void
    {
        $componente = $this->formulario()->set('cobro', 'prepaid');

        $componente->call('save')->assertHasErrors('cobro');

        $this->abrirCaja();

        $componente->call('save')->assertHasNoErrors();

        $this->assertSame(1, Invoice::count());
        $this->assertSame('Marta', Invoice::firstOrFail()->sender_name);
    }

    // ── Lo que NO necesita caja ───────────────────────────────────────

    /** Un «por cobrar» se cobra en destino: no mueve esta gaveta. */
    public function test_un_por_cobrar_no_necesita_caja_abierta(): void
    {
        $this->formulario()->set('cobro', 'collect')->call('save')->assertHasNoErrors();

        $this->assertSame(1, Invoice::count());
        $this->assertTrue(Invoice::firstOrFail()->esPorCobrar());
    }

    public function test_una_guia_a_credito_no_necesita_caja_abierta(): void
    {
        $cliente = Customer::create([
            'name' => 'Ferretería', 'identification' => '310112345678',
            'payment_condition' => Customer::PAYMENT_CREDIT, 'credit_limit' => 500000,
            'credit_cutoff_day' => 30, 'is_active' => true,
        ]);

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->set('cobro', 'credit')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Invoice::count());
    }

    /** Corregir un teléfono en una guía ya cobrada no puede exigir caja. */
    public function test_editar_una_guia_ya_cobrada_no_exige_caja(): void
    {
        $sesion = $this->abrirCaja();
        $this->formulario()->set('cobro', 'prepaid')->call('save')->assertHasNoErrors();

        $guia = Invoice::firstOrFail();

        app(CajaService::class)->cerrar($sesion, $this->cajero, [], 'cierre del día');

        Livewire::actingAs($this->cajero)
            ->test(InvoiceForm::class, ['invoice' => $guia])
            ->set('recipient_phone', '8888-9999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('8888-9999', $guia->fresh()->recipient_phone);
    }

    // ── El aviso que antes se perdía ──────────────────────────────────

    /** El redirect descartaba la propiedad del componente: ahora va por flash. */
    public function test_el_aviso_de_por_cobrar_sobrevive_al_redirect(): void
    {
        $this->formulario()->set('cobro', 'collect')->call('save');

        $this->assertSame(
            'Guía POR COBRAR: no entra al arqueo de esta caja. Se cobra en destino al momento de la entrega.',
            session('info')
        );
    }

    public function test_el_aviso_de_credito_tambien(): void
    {
        $cliente = Customer::create([
            'name' => 'Ferretería', 'identification' => '310112345678',
            'payment_condition' => Customer::PAYMENT_CREDIT, 'credit_limit' => 500000,
            'credit_cutoff_day' => 30, 'is_active' => true,
        ]);

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->set('cobro', 'credit')
            ->call('save');

        $this->assertStringContainsString('no entra al arqueo', (string) session('info'));
    }

    public function test_una_venta_de_contado_no_deja_aviso(): void
    {
        $this->abrirCaja();

        $this->formulario()->set('cobro', 'prepaid')->call('save');

        $this->assertNull(session('info'), 'Lo normal no necesita explicación.');
    }
}
