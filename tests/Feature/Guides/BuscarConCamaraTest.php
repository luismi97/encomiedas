<?php

namespace Tests\Feature\Guides;

use App\Livewire\Invoices\InvoiceIndex;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El buscador de guías usa la misma cámara de los choferes, pero de una
 * lectura: si el código es de una guía, la abre.
 */
class BuscarConCamaraTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Invoice $guia;

    protected function setUp(): void
    {
        parent::setUp();

        $sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'a@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->guia = Invoice::create([
            'status' => Invoice::STATUS_PENDING, 'pickup_branch_id' => $sj->id, 'delivery_branch_id' => $lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1000,
            'created_by' => $this->admin->id,
        ])->fresh();
    }

    public function test_el_buscador_tiene_el_boton_de_la_camara(): void
    {
        $this->actingAs($this->admin)
            ->get(route('invoices.index'))
            ->assertSee('data-test="buscar-con-camara"', false)
            ->assertSee('buscarEscaneado', false);
    }

    public function test_el_codigo_de_barras_abre_la_guia(): void
    {
        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->call('buscarEscaneado', $this->guia->code)
            ->assertRedirect(route('invoices.show', $this->guia));
    }

    /** El QR del recibo trae el enlace de rastreo, no solo el código. */
    public function test_el_qr_con_el_enlace_de_rastreo_tambien_abre_la_guia(): void
    {
        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->call('buscarEscaneado', $this->guia->trackingUrl())
            ->assertRedirect(route('invoices.show', $this->guia));
    }

    public function test_un_codigo_desconocido_queda_como_busqueda(): void
    {
        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->call('buscarEscaneado', 'XX-NADA-1')
            ->assertNoRedirect()
            ->assertSet('search', 'XX-NADA-1');
    }
}
