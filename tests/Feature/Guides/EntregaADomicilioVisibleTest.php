<?php

namespace Tests\Feature\Guides;

use App\Livewire\Dispatches\DispatchIndex;
use App\Livewire\Invoices\InvoiceIndex;
use App\Models\Branch;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «A domicilio» tiene que verse en el sistema, no solo en lo impreso.
 *
 * Solo lo decían la etiqueta y el recibo: quien armaba el cierre, recibía en
 * destino o buscaba la guía no se enteraba, y el paquete se quedaba en la sede
 * esperando a alguien que nunca iba a pasar a retirarlo.
 */
class EntregaADomicilioVisibleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;
    private Invoice $domicilio;
    private Invoice $sede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'a@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true, 'branch_id' => $this->sj->id]);

        $base = ['status' => Invoice::STATUS_PENDING, 'pickup_branch_id' => $this->sj->id,
            'delivery_branch_id' => $this->lim->id, 'sender_name' => 'Marta',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1000, 'created_by' => $this->admin->id];

        $this->domicilio = Invoice::create($base + ['recipient_name' => 'José', 'home_delivery' => true,
            'delivery_address' => 'Barrio Roosevelt, casa 14 portón verde', 'home_delivery_fee' => 1500])->fresh();
        $this->sede = Invoice::create($base + ['recipient_name' => 'Ana'])->fresh();
    }

    public function test_el_detalle_de_la_guia_muestra_la_direccion(): void
    {
        $this->actingAs($this->admin)->get(route('invoices.show', $this->domicilio))
            ->assertSee('A domicilio')
            ->assertSee('Barrio Roosevelt, casa 14 portón verde')
            ->assertSee('1,500.00')
            // En el desglose de montos, no solo en el recuadro de la dirección.
            ->assertSee('data-test="guia-domicilio">₡1,500.00', false);

        $this->actingAs($this->admin)->get(route('invoices.show', $this->sede))
            ->assertDontSee('data-test="a-domicilio"', false)
            ->assertDontSee('data-test="guia-domicilio"', false);
    }

    public function test_el_listado_lo_marca_y_se_puede_filtrar(): void
    {
        Livewire::actingAs($this->admin)->test(InvoiceIndex::class)
            ->assertSee('Barrio Roosevelt')
            ->set('entrega', 'domicilio')
            ->assertSee($this->domicilio->code)
            ->assertDontSee($this->sede->code)
            ->set('entrega', 'sede')
            ->assertSee($this->sede->code)
            ->assertDontSee($this->domicilio->code);
    }

    public function test_la_exportacion_respeta_el_filtro(): void
    {
        $this->actingAs($this->admin)
            ->get(route('invoices.export', ['entrega' => 'domicilio', 'period' => 'all']))
            ->assertOk();

        $this->assertSame(1, Invoice::entrega('domicilio')->count());
        $this->assertSame(1, Invoice::entrega('sede')->count());
        $this->assertSame(2, Invoice::entrega(null)->count());
    }

    public function test_el_cierre_de_envio_lo_muestra_en_pantalla_y_en_el_manifiesto(): void
    {
        $cierre = Dispatch::create(['code' => 'CIE-000001', 'origin_branch_id' => $this->sj->id,
            'destination_branch_id' => $this->lim->id, 'driver_name' => 'Chofer', 'created_by' => $this->admin->id])->fresh();

        // Disponible para agregar: ya se ve antes de cargarla al camión.
        Livewire::actingAs($this->admin)->test(DispatchIndex::class)
            ->call('open', $cierre->id)
            ->assertSeeHtml('data-test="a-domicilio"')
            ->assertSee('Barrio Roosevelt');

        app(DispatchService::class)->agregarGuia($cierre, $this->domicilio);
        app(DispatchService::class)->agregarGuia($cierre, $this->sede);

        // Dentro del cierre.
        Livewire::actingAs($this->admin)->test(DispatchIndex::class)
            ->call('open', $cierre->id)
            ->assertSee('Barrio Roosevelt');

        // Y en el manifiesto impreso, que es lo que viaja con el chofer.
        $html = view('pdf.dispatch', [
            'dispatch' => $cierre->fresh()->load(['lines.invoice.items', 'lines.invoice.deliveryBranch', 'originBranch', 'destinationBranch', 'driver', 'creator', 'guides.items']),
            'company' => \App\Models\CompanySetting::instance(),
        ])->render();

        $this->assertStringContainsString('A DOMICILIO: Barrio Roosevelt, casa 14 portón verde', $html);
        $this->assertStringContainsString('(1 a domicilio)', $html);
    }
}
