<?php

namespace Tests\Feature\Guides;

use App\Livewire\Invoices\InvoiceShow;
use App\Models\ElectronicInvoice;
use App\Models\GuideStatusHistory;
use App\Models\Invoice;
use App\Models\User;
use App\Services\GuideStatusService;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Hacienda\BuildsHaciendaFixtures;
use Tests\TestCase;

/**
 * Anular, devolver y desechar son del administrador.
 *
 *  - Anular: en cualquier estado. Si el comprobante ya fue aceptado, se emite
 *    la nota de crédito por lo que falte acreditar.
 *  - Devolver: con motivo.
 *  - Desechar: no antes de 3 meses en destino.
 */
class AdminAnulaDevuelveDesechaTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private User $admin;
    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->companySettings();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'adm', 'email' => 'adm@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->cajero = User::create(['name' => 'Cajero', 'username' => 'caj', 'email' => 'caj@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->branch()->id]);
    }

    private function servicio(): GuideStatusService
    {
        return app(GuideStatusService::class);
    }

    private function guiaEnDestino(int $diasDesdeLlegada = 1): Invoice
    {
        return $this->deliveredInvoice($this->branch(), [
            'status' => Invoice::STATUS_AT_DESTINATION,
            'delivered_at' => null,
            'arrived_at' => now()->subDays($diasDesdeLlegada),
        ]);
    }

    private function comprobanteAceptado(Invoice $guia): ElectronicInvoice
    {
        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($guia);
        $comprobante->forceFill(['status' => ElectronicInvoice::STATUS_ACCEPTED, 'total' => 11300])->save();

        return $comprobante->fresh();
    }

    // ── Anular ────────────────────────────────────────────────────────

    public function test_anular_una_entregada_con_comprobante_aceptado_emite_la_nota_de_credito(): void
    {
        $guia = $this->deliveredInvoice($this->branch());
        $comprobante = $this->comprobanteAceptado($guia);

        $this->servicio()->anular($guia, $this->admin, 'Cobro duplicado');

        $this->assertSame(Invoice::STATUS_CANCELLED, $guia->fresh()->status);

        $nota = ElectronicInvoice::where('reference_invoice_id', $comprobante->id)->firstOrFail();
        $this->assertSame('03', $nota->document_type);
        $this->assertEqualsWithDelta(11300, (float) $nota->total, 0.00001);
        $this->assertStringContainsString('Cobro duplicado', $nota->reference_reason);
    }

    /** Si ya tenía una nota parcial, la nueva solo acredita lo que falta. */
    public function test_la_nota_de_la_anulacion_acredita_solo_el_saldo(): void
    {
        $guia = $this->deliveredInvoice($this->branch());
        $comprobante = $this->comprobanteAceptado($guia);
        app(ElectronicBillingService::class)->issueNote($comprobante, 'NC', 'Descuento posterior', 1300);

        $this->servicio()->anular($guia, $this->admin, 'Se anula todo');

        $ultima = ElectronicInvoice::where('reference_invoice_id', $comprobante->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(10000, (float) $ultima->total, 0.00001);
    }

    public function test_no_se_anula_mientras_hacienda_no_contesta(): void
    {
        $guia = $this->deliveredInvoice($this->branch());
        app(ElectronicBillingService::class)->queueForInvoice($guia)
            ->forceFill(['status' => ElectronicInvoice::STATUS_SENT])->save();

        $this->expectExceptionMessage('esperando respuesta');

        $this->servicio()->anular($guia, $this->admin, 'Error');
    }

    /** Un comprobante pendiente de una guía anulada ya no se envía. */
    public function test_el_comprobante_pendiente_de_una_anulada_no_se_envia(): void
    {
        $guia = $this->deliveredInvoice($this->branch());
        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($guia);

        $this->servicio()->anular($guia, $this->admin, 'Error');

        $this->assertSame(
            'La guía está anulada: este comprobante no se envía.',
            app(ElectronicBillingService::class)->sendBlocker($comprobante->fresh())
        );
    }

    public function test_el_cajero_no_ve_anular_devolver_ni_desechar(): void
    {
        $guia = $this->guiaEnDestino(100);
        $estados = $guia->siguientesEstados($this->cajero);

        $this->assertArrayNotHasKey(Invoice::STATUS_CANCELLED, $estados);
        $this->assertArrayNotHasKey(Invoice::STATUS_RETURNED, $estados);

        $this->assertArrayHasKey(Invoice::STATUS_CANCELLED, $guia->siguientesEstados($this->admin));
        $this->assertArrayHasKey(Invoice::STATUS_RETURNED, $guia->siguientesEstados($this->admin));
    }

    // ── Devolver ──────────────────────────────────────────────────────

    public function test_el_administrador_devuelve_con_motivo(): void
    {
        $guia = $this->servicio()->devolver($this->guiaEnDestino(), $this->admin, 'El destinatario no la quiso');

        $this->assertSame(Invoice::STATUS_RETURNED, $guia->status);
        $this->assertSame('El destinatario no la quiso', $guia->return_reason);
        $this->assertSame($this->admin->id, $guia->returned_by);
        $this->assertSame('Devuelta: El destinatario no la quiso', $guia->statusHistories->last()->note);
    }

    public function test_devolver_exige_motivo(): void
    {
        $this->expectExceptionMessage('necesita un motivo');

        $this->servicio()->devolver($this->guiaEnDestino(), $this->admin, '  ');
    }

    public function test_el_cajero_no_devuelve(): void
    {
        $this->expectExceptionMessage(GuideStatusService::SOLO_ADMIN_DEVUELVE);

        $this->servicio()->devolver($this->guiaEnDestino(), $this->cajero, 'Motivo');
    }

    /** Ni por el atajo de cambiar(): sin motivo no hay devolución. */
    public function test_cambiar_a_devuelta_sin_motivo_no_pasa(): void
    {
        $this->expectException(RuntimeException::class);

        $this->servicio()->cambiar($this->guiaEnDestino(), Invoice::STATUS_RETURNED, $this->admin);
    }

    public function test_la_pantalla_pide_el_motivo_de_la_devolucion(): void
    {
        $guia = $this->guiaEnDestino();

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('updateStatus', Invoice::STATUS_RETURNED)
            ->assertSet('showReturnForm', true)
            ->set('returnReason', 'Dirección inexistente')
            ->call('devolver');

        $this->assertSame(Invoice::STATUS_RETURNED, $guia->fresh()->status);
    }

    // ── Desechar ──────────────────────────────────────────────────────

    public function test_no_se_desecha_antes_de_tres_meses(): void
    {
        $guia = $this->servicio()->cambiar($this->guiaEnDestino(60), Invoice::STATUS_NEAR_DISPOSAL, $this->admin);

        $this->expectExceptionMessage('antes de 3 meses');

        $this->servicio()->cambiar($guia, Invoice::STATUS_DISPOSED, $this->admin);
    }

    public function test_el_administrador_desecha_pasados_tres_meses(): void
    {
        $guia = $this->servicio()->cambiar($this->guiaEnDestino(95), Invoice::STATUS_NEAR_DISPOSAL, $this->admin);

        $guia = $this->servicio()->cambiar($guia, Invoice::STATUS_DISPOSED, $this->admin);

        $this->assertSame(Invoice::STATUS_DISPOSED, $guia->status);
    }

    public function test_el_cajero_no_desecha_aunque_pasen_tres_meses(): void
    {
        $guia = $this->servicio()->cambiar($this->guiaEnDestino(95), Invoice::STATUS_NEAR_DISPOSAL, $this->admin);

        $this->expectExceptionMessage(GuideStatusService::SOLO_ADMIN_DESECHA);

        $this->servicio()->cambiar($guia, Invoice::STATUS_DISPOSED, $this->cajero);
    }

    /** Sin usuario solo desecha el sistema: un cambio manual anónimo no pasa. */
    public function test_sin_usuario_un_cambio_manual_no_desecha(): void
    {
        $guia = $this->servicio()->cambiar($this->guiaEnDestino(95), Invoice::STATUS_NEAR_DISPOSAL, $this->admin);

        $this->expectExceptionMessage(GuideStatusService::SOLO_ADMIN_DESECHA);

        $this->servicio()->cambiar($guia, Invoice::STATUS_DISPOSED);
    }

    /** Ni el sistema desecha antes de los 3 meses. */
    public function test_el_sistema_tampoco_desecha_antes_de_tres_meses(): void
    {
        $guia = $this->servicio()->cambiar($this->guiaEnDestino(60), Invoice::STATUS_NEAR_DISPOSAL, $this->admin);

        $this->expectExceptionMessage('antes de 3 meses');

        $this->servicio()->cambiar($guia, Invoice::STATUS_DISPOSED, source: GuideStatusHistory::SOURCE_SYSTEM);
    }
}
