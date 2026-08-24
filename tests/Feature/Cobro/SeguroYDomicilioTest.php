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
 * Seguro sobre el valor declarado y entrega a domicilio.
 *
 * El valor declarado se pedía «para efectos de seguro» y no se cobraba nada por
 * él: la empresa asumía el riesgo gratis. Y la entrega a domicilio se acordaba
 * de palabra, sin dirección en la guía ni cargo en la factura.
 */
class SeguroYDomicilioTest extends TestCase
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

        app(CajaService::class)->abrir($this->sj->cashRegisters()->firstOrFail(), $this->cajero, 0);
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

    // ── El seguro del valor declarado ─────────────────────────────────

    /** 10.000 de flete + 7% de 100.000 declarados = 17.000 antes de impuesto. */
    public function test_el_valor_declarado_suma_su_porcentaje_al_cobro(): void
    {
        $this->formulario()
            ->set('declared_value', 100000)
            ->assertSet('insuranceFee', 7000.0)
            ->assertSet('taxableBase', 17000.0);
    }

    public function test_el_impuesto_se_calcula_sobre_el_total_con_seguro(): void
    {
        $this->formulario()
            ->set('declared_value', 100000)
            ->set('selectedTaxes', [Tax::first()->id])
            // (10.000 + 7.000) × 13% = 2.210
            ->assertSet('taxTotal', 2210.0)
            ->assertSet('total', 19210.0);
    }

    public function test_sin_valor_declarado_no_hay_seguro(): void
    {
        // El IVA viene marcado por defecto: 10.000 + 13% = 11.300, sin seguro.
        $this->formulario()
            ->assertSet('insuranceFee', 0.0)
            ->assertSet('taxableBase', 10000.0)
            ->assertSet('total', 11300.0);
    }

    public function test_el_seguro_queda_guardado_en_la_guia(): void
    {
        $this->formulario()->set('declared_value', 50000)->call('save')->assertHasNoErrors();

        $guia = Invoice::firstOrFail();
        $this->assertEquals(3500, $guia->insurance_fee);
        $this->assertEquals(50000, $guia->declared_value);
    }

    /** El porcentaje es configurable: no es una constante del código. */
    public function test_el_porcentaje_sale_de_la_configuracion(): void
    {
        $e = CompanySetting::instance();
        $e->insurance_percent = 10;
        $e->save();

        $this->formulario()->set('declared_value', 100000)->assertSet('insuranceFee', 10000.0);
    }

    /** Cambiar el porcentaje no puede reescribir lo que ya se cobró. */
    public function test_una_guia_vieja_conserva_lo_que_se_le_cobro(): void
    {
        $this->formulario()->set('declared_value', 100000)->call('save');
        $guia = Invoice::firstOrFail();
        $this->assertEquals(7000, $guia->insurance_fee);

        $e = CompanySetting::instance();
        $e->insurance_percent = 15;
        $e->save();

        $this->assertEquals(7000, $guia->fresh()->insurance_fee);
    }

    // ── Entrega a domicilio ───────────────────────────────────────────

    public function test_el_cargo_por_domicilio_suma_al_cobro(): void
    {
        $this->formulario()
            ->set('home_delivery', true)
            ->set('home_delivery_fee', 3000)
            ->assertSet('taxableBase', 13000.0);
    }

    public function test_a_domicilio_exige_direccion_exacta(): void
    {
        $this->formulario()
            ->set('home_delivery', true)
            ->set('home_delivery_fee', 3000)
            ->call('save')
            ->assertHasErrors('delivery_address')
            ->assertSee('dirección exacta');
    }

    public function test_con_direccion_se_guarda(): void
    {
        $this->formulario()
            ->set('home_delivery', true)
            ->set('home_delivery_fee', 3000)
            ->set('delivery_address', 'Limón, Cieneguita, 200m sur del super')
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::firstOrFail();
        $this->assertTrue($guia->esADomicilio());
        $this->assertSame('Limón, Cieneguita, 200m sur del super', $guia->delivery_address);
        $this->assertEquals(3000, $guia->home_delivery_fee);
    }

    /** Sin domicilio no se guarda dirección ni cargo aunque queden escritos. */
    public function test_desmarcar_domicilio_limpia_lo_suyo(): void
    {
        $this->formulario()
            ->set('home_delivery', true)
            ->set('delivery_address', 'una dirección')
            ->set('home_delivery_fee', 3000)
            ->set('home_delivery', false)
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::firstOrFail();
        $this->assertFalse($guia->esADomicilio());
        $this->assertNull($guia->delivery_address);
        $this->assertEquals(0, $guia->home_delivery_fee);
    }

    /** Los dos cargos conviven y ambos entran antes del impuesto. */
    public function test_seguro_y_domicilio_juntos(): void
    {
        $this->formulario()
            ->set('declared_value', 100000)
            ->set('home_delivery', true)
            ->set('home_delivery_fee', 3000)
            ->set('delivery_address', 'Limón centro')
            ->set('selectedTaxes', [Tax::first()->id])
            // 10.000 + 7.000 + 3.000 = 20.000 · +13% = 22.600
            ->assertSet('taxableBase', 20000.0)
            ->assertSet('total', 22600.0);
    }

    public function test_el_destino_muestra_la_direccion_cuando_es_a_domicilio(): void
    {
        $this->formulario()
            ->set('home_delivery', true)
            ->set('delivery_address', 'Cieneguita, 200m sur')
            ->set('home_delivery_fee', 2000)
            ->call('save');

        $this->assertSame('Cieneguita, 200m sur', Invoice::firstOrFail()->destinoLabel());
    }
}
