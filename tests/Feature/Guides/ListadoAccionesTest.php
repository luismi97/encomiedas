<?php

namespace Tests\Feature\Guides;

use App\Livewire\Invoices\InvoiceIndex;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El listado movía estados asignándolos a mano, saltándose la validación de
 * transiciones y la bitácora que la pantalla de detalle sí respetaba.
 */
class ListadoAccionesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function guia(): Invoice
    {
        return Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 3000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 3000,
            'created_by' => $this->admin->id,
        ])->fresh();
    }

    public function test_el_listado_ofrece_imprimir_la_etiqueta(): void
    {
        $guia = $this->guia();

        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->assertSee(route('invoices.recibo', $guia))
            ->assertSee('Etiqueta');
    }

    /** El cambio pasa por el servicio: valida, sella fechas y deja bitácora. */
    public function test_cambiar_estado_desde_el_listado_deja_bitacora(): void
    {
        $guia = $this->guia();
        $antes = $guia->statusHistories()->count();

        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->call('updateStatus', $guia->id, Invoice::STATUS_READY);

        $guia->refresh();

        $this->assertSame(Invoice::STATUS_READY, $guia->status);
        $this->assertSame($antes + 1, $guia->statusHistories()->count());
    }

    /** Antes se podía saltar de «Recibido» a «En camino» sin pasar por el ciclo. */
    public function test_no_se_puede_saltar_una_transicion_desde_el_listado(): void
    {
        $guia = $this->guia();

        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->call('updateStatus', $guia->id, Invoice::STATUS_IN_TRANSIT)
            ->assertSee('solo puede ir a');

        $this->assertSame(Invoice::STATUS_PENDING, $guia->fresh()->status);
    }

    /** Entregar exige registrar quién retiró: no se hace desde una lista. */
    public function test_entregar_desde_el_listado_manda_a_la_guia(): void
    {
        Bus::fake();
        $guia = $this->guia();
        $estados = app(GuideStatusService::class);

        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $e) {
            $guia = $estados->cambiar($guia, $e, $this->admin);
        }

        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->call('updateStatus', $guia->id, Invoice::STATUS_DELIVERED)
            ->assertSee('registrar quién la retira');

        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $guia->fresh()->status);
    }

    public function test_anular_desde_el_listado_manda_a_la_guia(): void
    {
        $guia = $this->guia();

        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->call('updateStatus', $guia->id, Invoice::STATUS_CANCELLED)
            ->assertSee('motivo de la anulación');

        $this->assertSame(Invoice::STATUS_PENDING, $guia->fresh()->status);
    }

    /** Los botones salen del ciclo, no de una cadena de @if desactualizada. */
    public function test_los_botones_reflejan_el_estado_actual(): void
    {
        $guia = $this->guia();

        // Contra la acción del botón y no contra el texto: el filtro de
        // estados lista todas las etiquetas y daría un falso positivo.
        // Las comillas simples salen escapadas como &#039; en el atributo.
        $accion = fn (string $estado) => "updateStatus({$guia->id}, &#039;{$estado}&#039;)";

        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->assertSee($accion('ready'), false)
            ->assertDontSee($accion('at_destination'), false)
            ->assertDontSee($accion('delivered'), false);
    }
}
