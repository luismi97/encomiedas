<?php

namespace Tests\Feature\Cobro;

use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Un descuento necesita autorización.
 *
 * Cualquier cajero podía rebajar lo que quisiera sin dejar rastro. Con la clave
 * configurada hay que digitarla, y la guía guarda quién la autorizó —que es lo
 * que sirve para auditar después—.
 */
class ClaveDeDescuentoTest extends TestCase
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

        $this->cajero = User::create(['name'=>'Ana Campos','username'=>'ana','email'=>'ana@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$this->sj->id]);

        app(CajaService::class)->abrir($this->sj->cashRegisters()->firstOrFail(), $this->cajero, 0);
    }

    private function conClave(string $clave = 'la-clave-del-jefe'): void
    {
        $e = CompanySetting::instance();
        $e->discount_authorization_code = $clave;
        $e->save();
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

    // ── Con clave configurada ─────────────────────────────────────────

    public function test_sin_la_clave_no_se_aplica_el_descuento(): void
    {
        $this->conClave();

        $this->formulario()
            ->set('discount_amount', 2000)
            ->call('save')
            ->assertHasErrors('discountCode')
            ->assertSee('clave de autorización no es correcta');

        $this->assertSame(0, Invoice::count());
    }

    public function test_con_una_clave_equivocada_tampoco(): void
    {
        $this->conClave();

        $this->formulario()
            ->set('discount_amount', 2000)
            ->set('discountCode', 'me-la-invento')
            ->call('save')
            ->assertHasErrors('discountCode');

        $this->assertSame(0, Invoice::count());
    }

    public function test_con_la_clave_correcta_pasa(): void
    {
        $this->conClave();

        $this->formulario()
            ->set('discount_amount', 2000)
            ->set('discountCode', 'la-clave-del-jefe')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(2000, Invoice::firstOrFail()->discount_amount);
    }

    /** Lo que sirve para auditar: quién lo autorizó. */
    public function test_queda_registrado_quien_autorizo(): void
    {
        $this->conClave();

        $this->formulario()
            ->set('discount_amount', 2000)
            ->set('discountCode', 'la-clave-del-jefe')
            ->call('save');

        $guia = Invoice::firstOrFail();
        $this->assertSame($this->cajero->id, $guia->discount_authorized_by);
        $this->assertSame('Ana Campos', $guia->discountAuthorizer->name);
    }

    /** Sin descuento no se pide clave: pedirla sería ruido. */
    public function test_sin_descuento_no_se_pide_nada(): void
    {
        $this->conClave();

        $this->formulario()->call('save')->assertHasNoErrors();

        $this->assertSame(1, Invoice::count());
        $this->assertNull(Invoice::firstOrFail()->discount_authorized_by);
    }

    public function test_el_campo_solo_aparece_cuando_hay_descuento(): void
    {
        $this->conClave();

        $this->formulario()
            ->assertDontSee('Clave de autorización')
            ->set('discount_amount', 500)
            ->assertSee('Clave de autorización');
    }

    // ── Sin clave configurada ─────────────────────────────────────────

    /** Es opcional: quien no la configure sigue operando como antes. */
    public function test_sin_clave_configurada_el_descuento_pasa_directo(): void
    {
        $this->formulario()
            ->set('discount_amount', 2000)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(2000, Invoice::firstOrFail()->discount_amount);
    }

    public function test_sin_clave_no_se_muestra_el_campo(): void
    {
        $this->formulario()
            ->set('discount_amount', 500)
            ->assertDontSee('Clave de autorización');
    }

    // ── La clave misma ────────────────────────────────────────────────

    public function test_la_clave_se_guarda_cifrada(): void
    {
        $this->conClave('secreta-del-jefe');

        $enBruto = \Illuminate\Support\Facades\DB::table('company_settings')
            ->value('discount_authorization_code');

        $this->assertNotSame('secreta-del-jefe', $enBruto);
        $this->assertTrue(CompanySetting::instance()->fresh()->claveDeDescuentoValida('secreta-del-jefe'));
    }

    public function test_una_clave_vacia_no_autoriza(): void
    {
        $this->conClave();
        $e = CompanySetting::instance()->fresh();

        $this->assertFalse($e->claveDeDescuentoValida(''));
        $this->assertFalse($e->claveDeDescuentoValida(null));
    }
}
