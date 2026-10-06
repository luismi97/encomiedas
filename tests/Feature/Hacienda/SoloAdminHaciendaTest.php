<?php

namespace Tests\Feature\Hacienda;

use App\Livewire\Invoices\InvoiceShow;
use App\Models\ElectronicInvoice;
use App\Models\User;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Solo el administrador ve y envía comprobantes a Hacienda. La pantalla ya
 * escondía la sección; esto cubre la acción invocada a mano y las descargas.
 */
class SoloAdminHaciendaTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->companySettings();

        $this->cajero = User::create(['name' => 'Caj', 'username' => 'caj', 'email' => 'caj@t.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->branch()->id]);
    }

    public function test_el_cajero_no_ve_la_seccion_ni_puede_enviar(): void
    {
        $guia = $this->deliveredInvoice($this->branch());

        Livewire::actingAs($this->cajero)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertDontSee('Facturación electrónica (Hacienda)')
            ->call('sendToHacienda')
            ->assertSee(InvoiceShow::SOLO_ADMIN_HACIENDA);

        $this->assertSame(0, ElectronicInvoice::count());
    }

    public function test_el_cajero_no_emite_notas(): void
    {
        $guia = $this->deliveredInvoice($this->branch());
        app(ElectronicBillingService::class)->queueForInvoice($guia)
            ->forceFill(['status' => ElectronicInvoice::STATUS_ACCEPTED, 'total' => 11300])->save();

        Livewire::actingAs($this->cajero)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->set('noteType', 'NC')->set('noteReason', 'Descuento posterior')->set('noteAmount', 100)
            ->call('issueNote');

        $this->assertSame(1, ElectronicInvoice::count());
    }

    public function test_el_cajero_no_descarga_comprobantes(): void
    {
        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($this->deliveredInvoice($this->branch()));

        $this->actingAs($this->cajero)->get(route('electronic-invoices.pdf', $comprobante))->assertForbidden();
        $this->actingAs($this->cajero)->get(route('electronic-invoices.response-xml', $comprobante))->assertForbidden();
    }
}
