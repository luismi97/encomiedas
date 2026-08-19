<?php

namespace Tests\Feature\Quotes;

use App\Livewire\Quotes\QuoteIndex;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Rate;
use App\Models\Tax;
use App\Models\User;
use App\Notifications\EnviarProforma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cotizaciones (proformas): precios por escrito que NO se facturan.
 *
 * Van en su propia tabla y no como guías en borrador porque una guía consume
 * consecutivo de ruta, entra en los reportes de venta y puede terminar en un
 * comprobante ante Hacienda. Una cotización no es nada de eso.
 */
class CotizadorTest extends TestCase
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

        Rate::create(['origin_branch_id'=>$this->sj->id,'destination_branch_id'=>$this->lim->id,
            'min_weight'=>0,'max_weight'=>10,'price'=>5000,'price_per_extra_kg'=>0,'is_active'=>true]);

        $this->cajero = User::create(['name'=>'Ana','username'=>'ana','email'=>'ana@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$this->sj->id]);
    }

    private function form()
    {
        return Livewire::actingAs($this->cajero)
            ->test(QuoteIndex::class)
            ->call('create')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->lim->id)
            ->set('customer_name', 'Ferretería El Roble');
    }

    // ── Crear ─────────────────────────────────────────────────────────

    public function test_se_crea_una_cotizacion_con_su_consecutivo(): void
    {
        $this->form()->set('items.0.price', 5000)->call('save')->assertHasNoErrors();

        $cot = Quote::firstOrFail();
        $this->assertSame('COT-000001', $cot->code);
        $this->assertSame('Ferretería El Roble', $cot->customer_name);
        $this->assertSame('Borrador', $cot->estadoLabel());
    }

    public function test_el_consecutivo_avanza(): void
    {
        $this->form()->set('items.0.price', 5000)->call('save');
        $this->form()->set('items.0.price', 7000)->call('save');

        $this->assertSame(['COT-000001', 'COT-000002'], Quote::orderBy('id')->pluck('code')->all());
    }

    /** Lo esencial: una cotización no es una venta. */
    public function test_una_cotizacion_no_crea_ninguna_guia(): void
    {
        $this->form()->set('items.0.price', 5000)->call('save');

        $this->assertSame(0, Invoice::count());
        $this->assertNull(Quote::firstOrFail()->invoice_id);
    }

    public function test_usa_el_mismo_tarifario_que_las_guias(): void
    {
        Livewire::actingAs($this->cajero)
            ->test(QuoteIndex::class)
            ->call('create')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->lim->id)
            ->set('items.0.weight', '3')
            ->assertSet('items.0.price', 5000.0);
    }

    public function test_calcula_impuestos_y_total(): void
    {
        $this->form()->set('items.0.price', 10000)->call('save');

        $cot = Quote::firstOrFail();
        $this->assertEquals(10000, $cot->subtotal);
        $this->assertEquals(1300, $cot->tax_total);
        $this->assertEquals(11300, $cot->total);
    }

    public function test_se_puede_cotizar_sin_impuestos(): void
    {
        $this->form()->set('aplicarImpuesto', false)->set('items.0.price', 10000)->call('save');

        $cot = Quote::firstOrFail();
        $this->assertEquals(0, $cot->tax_total);
        $this->assertEquals(10000, $cot->total);
    }

    public function test_varios_bultos_suman(): void
    {
        $this->form()
            ->set('items.0.price', 5000)
            ->call('addItem')
            ->set('items.1.price', 3000)
            ->call('save');

        $cot = Quote::with('items')->firstOrFail();
        $this->assertCount(2, $cot->items);
        $this->assertEquals(8000, $cot->subtotal);
    }

    public function test_elegir_un_cliente_rellena_sus_datos(): void
    {
        $cliente = Customer::create(['name'=>'Marta Solano','email'=>'marta@t.test','phone'=>'8888-1111','is_active'=>true]);

        Livewire::actingAs($this->cajero)
            ->test(QuoteIndex::class)
            ->call('create')
            ->set('customer_id', $cliente->id)
            ->assertSet('customer_name', 'Marta Solano')
            ->assertSet('customer_email', 'marta@t.test');
    }

    // ── Validaciones ──────────────────────────────────────────────────

    public function test_no_se_cotiza_de_una_sede_a_si_misma(): void
    {
        $this->form()
            ->set('destination_branch_id', $this->sj->id)
            ->set('items.0.price', 5000)
            ->call('save')
            ->assertHasErrors('destination_branch_id');
    }

    public function test_hace_falta_a_nombre_de_quien(): void
    {
        $this->form()->set('customer_name', '')->set('items.0.price', 5000)
            ->call('save')->assertHasErrors('customer_name');
    }

    public function test_no_vence_antes_de_hoy(): void
    {
        $this->form()
            ->set('valid_until', now()->subDay()->toDateString())
            ->set('items.0.price', 5000)
            ->call('save')
            ->assertHasErrors('valid_until');
    }

    // ── Descargar ─────────────────────────────────────────────────────

    public function test_se_descarga_el_pdf(): void
    {
        $this->form()->set('items.0.price', 5000)->call('save');

        $this->actingAs($this->cajero)
            ->get(route('quotes.pdf', Quote::firstOrFail()))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /** El PDF tiene que decir claramente que no es una factura. */
    public function test_el_pdf_aclara_que_no_es_una_factura(): void
    {
        $this->form()->set('items.0.price', 5000)->call('save');

        $html = view('pdf.quote', [
            'cotizacion' => Quote::with(['items.packageType','originBranch','destinationBranch','creator'])->firstOrFail(),
            'empresa' => \App\Models\CompanySetting::instance(),
        ])->render();

        $this->assertStringContainsString('es una cotización, no una factura', $html);
        $this->assertStringContainsString('No tiene validez tributaria', $html);
        $this->assertStringContainsString('no constituye un cobro', $html);
        $this->assertStringContainsString('COT-000001', $html);
    }

    // ── Enviar por correo ─────────────────────────────────────────────

    public function test_se_envia_por_correo_al_cliente(): void
    {
        Notification::fake();

        $this->form()->set('customer_email', 'cliente@t.test')->set('items.0.price', 5000)->call('save');
        $cot = Quote::firstOrFail();

        Livewire::actingAs($this->cajero)
            ->test(QuoteIndex::class)
            ->call('abrirEnvio', $cot->id)
            ->assertSet('enviarA', 'cliente@t.test')
            ->call('enviar')
            ->assertSet('feedbackType', 'success');

        Notification::assertSentOnDemand(EnviarProforma::class);

        $cot->refresh();
        $this->assertNotNull($cot->sent_at);
        $this->assertSame('cliente@t.test', $cot->sent_to);
        $this->assertSame('Enviada', $cot->estadoLabel());
    }

    public function test_no_se_envia_sin_un_correo_valido(): void
    {
        Notification::fake();

        $this->form()->set('items.0.price', 5000)->call('save');
        $cot = Quote::firstOrFail();

        Livewire::actingAs($this->cajero)
            ->test(QuoteIndex::class)
            ->call('abrirEnvio', $cot->id)
            ->set('enviarA', 'no-es-un-correo')
            ->call('enviar')
            ->assertHasErrors('enviarA');

        Notification::assertNothingSent();
    }

    public function test_el_correo_lleva_el_pdf_adjunto(): void
    {
        $this->form()->set('items.0.price', 5000)->call('save');
        $cot = Quote::with(['items.packageType','originBranch','destinationBranch'])->firstOrFail();

        $mail = (new EnviarProforma($cot))->toMail((object) []);
        $adjuntos = $mail->rawAttachments;

        $this->assertCount(1, $adjuntos);
        $this->assertSame('COT-000001.pdf', $adjuntos[0]['name']);
        $this->assertStringStartsWith('%PDF', $adjuntos[0]['data']);
    }

    // ── Ciclo de vida ─────────────────────────────────────────────────

    public function test_una_cotizacion_vencida_se_marca_como_tal(): void
    {
        $this->form()->set('items.0.price', 5000)->call('save');

        $cot = Quote::firstOrFail();
        $cot->forceFill(['valid_until' => now()->subDay()])->save();

        $this->assertTrue($cot->fresh()->estaVencida());
        $this->assertSame('Vencida', $cot->fresh()->estadoLabel());
    }

    public function test_una_aceptada_no_se_edita_ni_se_borra(): void
    {
        $this->form()->set('items.0.price', 5000)->call('save');
        $cot = Quote::firstOrFail();

        $guia = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->sj->id,
            'delivery_branch_id'=>$this->lim->id,'sender_name'=>'M','recipient_name'=>'J',
            'subtotal'=>5000,'discount_amount'=>0,'tax_total'=>0,'total'=>5000,'created_by'=>$this->cajero->id]);
        $cot->forceFill(['invoice_id' => $guia->id])->save();

        $panel = Livewire::actingAs($this->cajero)->test(QuoteIndex::class);

        $panel->call('edit', $cot->id)->assertSet('feedbackType', 'error');
        $panel->call('delete', $cot->id)->assertSet('feedbackType', 'error');

        $this->assertNotNull(Quote::find($cot->id));
        $this->assertSame('Aceptada', $cot->fresh()->estadoLabel());
    }

    public function test_una_cotizacion_sin_aceptar_si_se_borra(): void
    {
        $this->form()->set('items.0.price', 5000)->call('save');
        $cot = Quote::firstOrFail();

        Livewire::actingAs($this->cajero)
            ->test(QuoteIndex::class)
            ->call('delete', $cot->id)
            ->assertSet('feedbackType', 'success');

        $this->assertNull(Quote::find($cot->id));
    }

    // ── Permisos ──────────────────────────────────────────────────────

    public function test_el_cajero_entra_al_cotizador(): void
    {
        $this->actingAs($this->cajero)->get(route('quotes.index'))->assertOk();
    }

    public function test_el_repartidor_no_entra(): void
    {
        $r = User::create(['name'=>'R','username'=>'r','email'=>'r@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_REPARTIDOR,'is_active'=>true]);

        $this->actingAs($r)->get(route('quotes.index'))->assertForbidden();
    }
}
