<?php

namespace Tests\Feature\Rutas;

use App\Livewire\Dispatches\DispatchIndex;
use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\ShippingRoutes\ShippingRouteIndex;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\ShippingRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rutas predefinidas: el par origen–destino como una sola elección.
 *
 * Lo que se prueba es el atajo y su límite: rellena las dos sedes, pero no
 * manda. Si después alguien corrige una sede a mano, la ruta se suelta, porque
 * una guía que dice ir por una ruta que no es promete una fecha que no se va a
 * cumplir.
 */
class RutasPredefinidasTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private Branch $her;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        $this->her = Branch::create(['name'=>'Heredia','prefix'=>'HER','sucursal_code'=>'003','terminal_code'=>'00001','is_active'=>true]);

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true,'branch_id'=>$this->sj->id]);
    }

    private function ruta(?Branch $origen = null, ?Branch $destino = null, ?int $transito = 2): ShippingRoute
    {
        return ShippingRoute::create([
            'name' => 'Limón directo',
            'origin_branch_id' => ($origen ?? $this->sj)->id,
            'destination_branch_id' => ($destino ?? $this->lim)->id,
            'transit_days' => $transito,
            'is_active' => true,
        ])->fresh();
    }

    // ── Administración ────────────────────────────────────────────────

    public function test_se_crea_una_ruta_con_nombre_y_transito(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ShippingRouteIndex::class)
            ->call('create')
            ->set('name', 'Limón directo')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->lim->id)
            ->set('transit_days', 2)
            ->call('save')
            ->assertHasNoErrors();

        $ruta = ShippingRoute::first();

        $this->assertSame('Limón directo', $ruta->name);
        $this->assertSame(2, $ruta->transit_days);
        $this->assertSame('SJ → LIM', $ruta->rutaLabel());
    }

    /** Dos rutas iguales con nombres distintos obligan a adivinar cuál usar. */
    public function test_no_se_repite_el_mismo_par_de_sedes(): void
    {
        $this->ruta();

        Livewire::actingAs($this->admin)
            ->test(ShippingRouteIndex::class)
            ->call('create')
            ->set('name', 'Limón por la costa')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->lim->id)
            ->call('save')
            ->assertHasErrors('destination_branch_id');
    }

    /** Lo que se deja y se retira en la misma sede, como en la guía. */
    public function test_una_ruta_puede_quedarse_en_la_misma_sede(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ShippingRouteIndex::class)
            ->call('create')
            ->set('name', 'Retiro en San José')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->sj->id)
            ->call('save')
            ->assertHasNoErrors();

        $ruta = ShippingRoute::firstOrFail();
        $this->assertSame($ruta->origin_branch_id, $ruta->destination_branch_id);

        // Tampoco se repite.
        Livewire::actingAs($this->admin)
            ->test(ShippingRouteIndex::class)
            ->call('create')
            ->set('name', 'Otra en San José')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->sj->id)
            ->call('save')
            ->assertHasErrors('destination_branch_id')
            ->assertSee('dentro de esa sede');
    }

    public function test_la_guia_con_ruta_de_la_misma_sede_se_guarda(): void
    {
        $ruta = $this->ruta($this->sj, $this->sj, null);

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('shipping_route_id', $ruta->id)
            ->assertSet('pickup_branch_id', $this->sj->id)
            ->assertSet('delivery_branch_id', $this->sj->id)
            ->set('cobro', InvoiceForm::COBRO_COLLECT)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.price', 1000)
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::firstOrFail();
        $this->assertSame($ruta->id, $guia->shipping_route_id);
        $this->assertTrue($guia->esMismaSede());
    }

    /** No viaja: el cierre no la ofrece. */
    public function test_el_cierre_no_ofrece_rutas_de_la_misma_sede(): void
    {
        $this->ruta($this->sj, $this->sj, null)->update(['name' => 'Retiro en San José']);
        $this->ruta()->update(['name' => 'Limón directo']);

        $rutas = Livewire::actingAs($this->admin)->test(DispatchIndex::class)->viewData('rutas');

        $this->assertSame(['Limón directo'], $rutas->pluck('name')->all());
    }

    /** Borrarla dejaría sin fecha prometida a guías que ya salieron con ella. */
    public function test_una_ruta_usada_no_se_borra_pero_se_desactiva(): void
    {
        $ruta = $this->ruta();

        Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->lim->id,
            'shipping_route_id' => $ruta->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1000,
            'created_by' => $this->admin->id,
        ]);

        $componente = Livewire::actingAs($this->admin)->test(ShippingRouteIndex::class);

        $componente->call('delete', $ruta->id);
        $this->assertNotNull(ShippingRoute::find($ruta->id));

        $componente->call('toggleActive', $ruta->id);
        $this->assertFalse(ShippingRoute::find($ruta->id)->is_active);
    }

    // ── El atajo en el formulario de guías ────────────────────────────

    public function test_elegir_la_ruta_rellena_las_dos_sucursales(): void
    {
        $ruta = $this->ruta();

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('shipping_route_id', $ruta->id)
            ->assertSet('pickup_branch_id', $this->sj->id)
            ->assertSet('delivery_branch_id', $this->lim->id);
    }

    /** El atajo no manda: una guía hacia una sede sin ruta se factura igual. */
    public function test_se_puede_crear_una_guia_sin_ruta(): void
    {
        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->her->id)
            ->assertSet('shipping_route_id', null)
            ->assertHasNoErrors();
    }

    /**
     * Corregir una sede a mano suelta la ruta: guardar la vieja sería prometer
     * el plazo de un viaje que esta guía ya no hace.
     */
    public function test_cambiar_una_sede_a_mano_suelta_la_ruta(): void
    {
        $ruta = $this->ruta();

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('shipping_route_id', $ruta->id)
            ->assertSet('shipping_route_id', $ruta->id)
            ->set('delivery_branch_id', $this->her->id)
            ->assertSet('shipping_route_id', null);
    }

    public function test_la_ruta_queda_guardada_en_la_guia(): void
    {
        $ruta = $this->ruta();

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('shipping_route_id', $ruta->id)
            // Por cobrar: así la prueba no depende de tener una caja abierta,
            // que es otra cosa y ya tiene sus propias pruebas.
            ->set('cobro', InvoiceForm::COBRO_COLLECT)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.weight', 2)
            ->set('items.0.price', 1000)
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::first();

        $this->assertSame($ruta->id, $guia->shipping_route_id);
        $this->assertSame(
            $guia->created_at->copy()->addDays(2)->toDateString(),
            $guia->llegadaEstimada()->toDateString()
        );
    }

    /** Sin ruta no hay promesa: es preferible no decir nada a inventar un plazo. */
    public function test_una_guia_sin_ruta_no_promete_fecha(): void
    {
        $guia = Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->her->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1000,
            'created_by' => $this->admin->id,
        ]);

        $this->assertNull($guia->llegadaEstimada());
    }

    // ── El atajo en los cierres ───────────────────────────────────────

    public function test_la_ruta_tambien_arma_la_cabecera_del_cierre(): void
    {
        $ruta = $this->ruta();

        Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('create')
            ->set('shipping_route_id', $ruta->id)
            ->assertSet('origin_branch_id', $this->sj->id)
            ->assertSet('destination_branch_id', $this->lim->id);
    }

    // ── Los días de tránsito afinan el aviso de estancadas ────────────

    /**
     * Una ruta de dos días no está estancada al tercero, pero tampoco hay que
     * esperar los siete del umbral general para notarlo.
     */
    public function test_la_guia_de_una_ruta_corta_se_nota_antes(): void
    {
        config(['encomiendas.stuck_after_days' => 7, 'encomiendas.stuck_margin_days' => 3]);

        $ruta = $this->ruta(transito: 2);   // 2 + 3 = se mira a los 5 días

        $guia = $this->guiaEnTransito($ruta, diasAtras: 6);

        $this->artisan('guias:desecho')
            ->expectsOutputToContain('Estancadas en tránsito: 1')
            ->expectsOutputToContain($guia->code)
            ->expectsOutputToContain('Limón directo')
            ->assertSuccessful();
    }

    public function test_dentro_del_transito_de_su_ruta_no_se_reporta(): void
    {
        config(['encomiendas.stuck_after_days' => 7, 'encomiendas.stuck_margin_days' => 3]);

        $ruta = $this->ruta(transito: 2);
        $this->guiaEnTransito($ruta, diasAtras: 3);

        $this->artisan('guias:desecho')
            ->expectsOutputToContain('Estancadas en tránsito: 0')
            ->assertSuccessful();
    }

    private function guiaEnTransito(?ShippingRoute $ruta, int $diasAtras): Invoice
    {
        $guia = Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->lim->id,
            'shipping_route_id' => $ruta?->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1000,
            'created_by' => $this->admin->id,
        ]);

        $estados = app(\App\Services\GuideStatusService::class);
        $guia = $estados->cambiar($guia->fresh(), Invoice::STATUS_READY, $this->admin);
        $guia = $estados->cambiar($guia, Invoice::STATUS_DISPATCHED, $this->admin);

        \App\Models\GuideStatusHistory::where('invoice_id', $guia->id)
            ->update(['happened_at' => now()->subDays($diasAtras)]);

        return $guia->fresh();
    }
}
