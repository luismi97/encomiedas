<?php

namespace Tests\Feature\Guides;

use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Concerns\AbreLaCaja;
use Tests\Feature\Hacienda\BuildsHaciendaFixtures;
use Tests\TestCase;

/**
 * Dos sobres iguales van en una sola línea con cantidad 2.
 *
 * El precio se digita por bulto; la línea guarda el total (precio × cantidad)
 * para que caja, reportes y crédito, que suman `price`, no cambien.
 */
class CantidadPorLineaTest extends TestCase
{
    use RefreshDatabase;
    use AbreLaCaja;
    use BuildsHaciendaFixtures;

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        Tax::create(['name' => 'IVA', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        $this->abrirCajaDe($this->sj, $this->admin);
    }

    public function test_la_linea_guarda_cantidad_y_total_de_la_linea(): void
    {
        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.quantity', 3)
            ->set('items.0.price', 1000)
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::firstOrFail();
        $linea = $guia->items->first();

        $this->assertSame(3, $linea->quantity);
        $this->assertEquals(3000, (float) $linea->price, 'price es el total de la línea.');
        $this->assertEquals(1000, $linea->precioUnitario());
        $this->assertEquals(3000, (float) $guia->subtotal);
        $this->assertSame(3, $guia->cantidadDeBultos());
    }

    public function test_la_cantidad_minima_es_uno(): void
    {
        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.quantity', 0)
            ->set('items.0.price', 1000)
            ->call('save')
            ->assertHasErrors(['items.0.quantity']);
    }

    /** Al editar, el formulario vuelve a mostrar el precio por bulto. */
    public function test_al_editar_se_ve_el_precio_por_bulto(): void
    {
        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.quantity', 2)
            ->set('items.0.price', 1500)
            ->call('save');

        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class, ['invoice' => Invoice::firstOrFail()])
            ->assertSet('items.0.quantity', 2)
            ->assertSet('items.0.price', 1500.0);
    }

    /** Hacienda valida PrecioUnitario × Cantidad = MontoTotal. */
    public function test_el_comprobante_lleva_la_cantidad_y_el_precio_unitario(): void
    {
        Bus::fake();
        $this->companySettings();
        $guia = $this->deliveredInvoice($this->branch());
        $guia->items()->orderBy('id')->first()->update(['quantity' => 3]); // 6000 = 3 × 2000

        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($guia->fresh());
        $xml = simplexml_load_string((new FacturaElectronicaXml($comprobante))->build());

        $linea = $xml->DetalleServicio->LineaDetalle[0];
        $this->assertEquals(3, (float) $linea->Cantidad);
        $this->assertEqualsWithDelta(2000, (float) $linea->PrecioUnitario, 0.00001);
        $this->assertEqualsWithDelta(6000, (float) $linea->MontoTotal, 0.00001);
        $this->assertEqualsWithDelta(11300, (float) $xml->ResumenFactura->TotalComprobante, 0.00001);
    }

    public function test_el_recibo_dice_la_cantidad(): void
    {
        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.quantity', 2)
            ->set('items.0.price', 1000)
            ->call('save');

        $this->actingAs($this->admin)
            ->get(route('invoices.recibo', Invoice::firstOrFail()))
            ->assertOk()
            ->assertSee('2 × ');
    }
}
