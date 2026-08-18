<?php

namespace Tests\Feature\Guides;

use App\Livewire\Invoices\InvoiceShow;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AnulacionYEntregaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);

        $this->usuario = User::create([
            'name' => 'Supervisor', 'username' => 'supervisor', 'email' => 'sup@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function guia(): Invoice
    {
        return Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id,
            'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José Fernández',
            'recipient_identification' => '112340567',
            'subtotal' => 5000, 'discount_amount' => 0, 'tax_total' => 650, 'total' => 5650,
            'created_by' => $this->usuario->id,
        ])->fresh();
    }

    private function servicio(): GuideStatusService
    {
        return app(GuideStatusService::class);
    }

    // ── Anulación ─────────────────────────────────────────────────────

    public function test_anular_deja_motivo_autor_y_fecha(): void
    {
        $guia = $this->servicio()->anular($this->guia(), $this->usuario, 'El cliente desistió del envío');

        $this->assertSame(Invoice::STATUS_CANCELLED, $guia->status);
        $this->assertSame('El cliente desistió del envío', $guia->cancellation_reason);
        $this->assertSame($this->usuario->id, $guia->cancelled_by);
        $this->assertNotNull($guia->cancelled_at);
    }

    /** Una anulación sin explicación es justo lo que no se puede auditar. */
    public function test_el_motivo_es_obligatorio(): void
    {
        $this->expectExceptionMessage('necesita un motivo');

        $this->servicio()->anular($this->guia(), $this->usuario, '   ');
    }

    public function test_el_motivo_queda_en_la_bitacora(): void
    {
        $guia = $this->servicio()->anular($this->guia(), $this->usuario, 'Error de digitación');

        $ultimo = $guia->statusHistories()->latest('id')->first();

        $this->assertStringContainsString('Anulada: Error de digitación', $ultimo->note);
        $this->assertSame($this->usuario->id, $ultimo->user_id);
    }

    /**
     * Una encomienda que ya salió viaja en un camión con un manifiesto firmado:
     * se devuelve, no se anula.
     */
    public function test_una_guia_despachada_no_se_anula(): void
    {
        Bus::fake();
        $guia = $this->guia();

        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED] as $estado) {
            $guia = $this->servicio()->cambiar($guia, $estado, $this->usuario);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('se devuelve, no se anula');

        $this->servicio()->anular($guia, $this->usuario, 'Ya no lo quieren');
    }

    public function test_una_guia_lista_todavia_se_puede_anular(): void
    {
        $guia = $this->servicio()->cambiar($this->guia(), Invoice::STATUS_READY, $this->usuario);

        $anulada = $this->servicio()->anular($guia, $this->usuario, 'Se dañó en bodega');

        $this->assertSame(Invoice::STATUS_CANCELLED, $anulada->status);
    }

    // ── Entrega con evidencia ─────────────────────────────────────────

    private function guiaEnDestino(): Invoice
    {
        Bus::fake();
        $guia = $this->guia();

        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $estado) {
            $guia = $this->servicio()->cambiar($guia, $estado, $this->usuario);
        }

        return $guia;
    }

    public function test_entregar_registra_quien_retiro(): void
    {
        $firma = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $guia = $this->servicio()->entregar(
            $this->guiaEnDestino(), $this->usuario, 'Carlos Umaña', '1-1234-0567', $firma
        );

        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->status);
        $this->assertSame('Carlos Umaña', $guia->received_by_name);
        $this->assertSame('112340567', $guia->received_by_identification);
        $this->assertSame($firma, $guia->delivery_signature);
        $this->assertTrue($guia->tieneEvidenciaDeEntrega());
    }

    public function test_no_se_entrega_sin_registrar_quien_retira(): void
    {
        $this->expectExceptionMessage('nombre de quien retira');

        $this->servicio()->entregar($this->guiaEnDestino(), $this->usuario, '  ');
    }

    /** La firma llega del navegador: no se guarda cualquier cadena. */
    public function test_una_firma_que_no_es_imagen_se_descarta(): void
    {
        $guia = $this->servicio()->entregar(
            $this->guiaEnDestino(), $this->usuario, 'Carlos Umaña', null,
            '<script>alert(1)</script>'
        );

        $this->assertNull($guia->delivery_signature);
        // La entrega igual se registra: el paquete ya se entregó físicamente.
        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->status);
    }

    public function test_se_puede_entregar_sin_firma(): void
    {
        $guia = $this->servicio()->entregar($this->guiaEnDestino(), $this->usuario, 'Carlos Umaña');

        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->status);
        $this->assertNull($guia->delivery_signature);
        $this->assertTrue($guia->tieneEvidenciaDeEntrega());
    }

    public function test_quien_retiro_queda_en_la_bitacora(): void
    {
        $guia = $this->servicio()->entregar($this->guiaEnDestino(), $this->usuario, 'Carlos Umaña');

        $this->assertStringContainsString('Retirada por Carlos Umaña',
            $guia->statusHistories()->latest('id')->first()->note);
    }

    // ── Interfaz ──────────────────────────────────────────────────────

    public function test_el_boton_de_entregar_abre_el_formulario_de_evidencia(): void
    {
        $guia = $this->guiaEnDestino();

        Livewire::actingAs($this->usuario)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('updateStatus', Invoice::STATUS_DELIVERED)
            ->assertSet('showDeliveryForm', true)
            // Precarga el destinatario: casi siempre retira él.
            ->assertSet('receivedByName', 'José Fernández');
    }

    public function test_el_boton_de_anular_abre_el_formulario_de_motivo(): void
    {
        Livewire::actingAs($this->usuario)
            ->test(InvoiceShow::class, ['invoice' => $this->guia()])
            ->call('updateStatus', Invoice::STATUS_CANCELLED)
            ->assertSet('showCancelForm', true);
    }

    public function test_la_evidencia_se_muestra_en_la_pantalla(): void
    {
        $guia = $this->servicio()->entregar($this->guiaEnDestino(), $this->usuario, 'Carlos Umaña', '112340567');

        Livewire::actingAs($this->usuario)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertSee('Evidencia de entrega')
            ->assertSee('Carlos Umaña');
    }
}
