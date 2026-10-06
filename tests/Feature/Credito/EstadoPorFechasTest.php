<?php

namespace Tests\Feature\Credito;

use App\Livewire\Credito\CreditoPanel;
use App\Models\Branch;
use App\Models\CreditPayment;
use App\Models\CreditStatement;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Estado de cuenta de un cliente de crédito para cualquier rango de fechas.
 *
 * Es informativo: no corta ni le pone vencimiento a nada. Trae las guías del
 * rango —cortadas o no—, los abonos de esas fechas y el saldo de hoy.
 */
class EstadoPorFechasTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sede;
    private Customer $cliente;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->cliente = Customer::create([
            'name' => 'Ferretería El Roble S.A.', 'identification' => '3101778899', 'identification_type' => '02',
            'payment_condition' => Customer::PAYMENT_CREDIT, 'credit_limit' => 500000, 'credit_cutoff_day' => 30,
        ]);
        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function guia(float $total, string $fecha, array $extra = []): Invoice
    {
        $guia = Invoice::create(array_merge([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sede->id, 'delivery_branch_id' => $this->sede->id,
            'sender_customer_id' => $this->cliente->id,
            'sender_name' => 'Ferretería', 'recipient_name' => 'Destino ' . $fecha,
            'subtotal' => $total, 'discount_amount' => 0, 'tax_total' => 0, 'total' => $total,
            'sale_condition' => Invoice::SALE_CREDIT,
            'created_by' => $this->admin->id,
        ], $extra));

        $guia->forceFill(['created_at' => $fecha . ' 10:00:00'])->save();

        return $guia->fresh();
    }

    private function estado(string $desde, string $hasta): array
    {
        return app(CreditoService::class)->estadoPorFechas($this->cliente, $desde, $hasta);
    }

    public function test_trae_solo_las_guias_a_credito_del_rango(): void
    {
        $this->guia(10000, '2026-08-31');
        $this->guia(20000, '2026-09-01');
        $this->guia(30000, '2026-09-15');
        $this->guia(40000, '2026-09-30');
        $this->guia(50000, '2026-10-01');

        $r = $this->estado('2026-09-01', '2026-09-30');

        $this->assertCount(3, $r['guias'], 'Los dos extremos del rango entran completos.');
        $this->assertSame(90000.0, $r['consumido']);
    }

    public function test_no_trae_anuladas_ni_de_contado_ni_de_otro_cliente(): void
    {
        $otro = Customer::create(['name' => 'Otro', 'payment_condition' => Customer::PAYMENT_CREDIT]);

        $this->guia(10000, '2026-09-10');
        $this->guia(20000, '2026-09-10', ['status' => Invoice::STATUS_CANCELLED]);
        $this->guia(30000, '2026-09-10', ['sale_condition' => Invoice::SALE_CASH]);
        $this->guia(40000, '2026-09-10', ['sender_customer_id' => $otro->id]);

        $this->assertSame(10000.0, $this->estado('2026-09-01', '2026-09-30')['consumido']);
    }

    /** No corta nada: a diferencia del corte, también trae lo ya cortado. */
    public function test_trae_las_cortadas_y_no_corta_las_pendientes(): void
    {
        $this->guia(10000, '2026-09-05');
        app(CreditoService::class)->cortar($this->cliente, $this->admin, now()->setDate(2026, 9, 10));
        $this->guia(20000, '2026-09-20');

        $r = $this->estado('2026-09-01', '2026-09-30');

        $this->assertCount(2, $r['guias']);
        $this->assertNotNull($r['guias']->first()->creditStatement);
        $this->assertNull($r['guias']->last()->fresh()->credit_statement_id, 'La pendiente sigue sin cortar.');
        $this->assertSame(1, CreditStatement::count());
    }

    public function test_trae_los_abonos_del_rango_y_el_saldo_de_hoy(): void
    {
        $this->guia(100000, '2026-08-20');
        app(CreditoService::class)->cortar($this->cliente, $this->admin, now()->setDate(2026, 8, 31));

        $abono = app(CreditoService::class)->abonar($this->cliente, 30000, $this->admin);
        $abono->forceFill(['paid_at' => '2026-09-12 09:00:00'])->save();
        $viejo = app(CreditoService::class)->abonar($this->cliente, 5000, $this->admin);
        $viejo->forceFill(['paid_at' => '2026-08-25 09:00:00'])->save();

        $r = $this->estado('2026-09-01', '2026-09-30');

        $this->assertCount(1, $r['abonos']);
        $this->assertSame(30000.0, $r['abonado']);
        $this->assertSame(65000.0, $r['saldoActual'], 'El saldo es el de hoy, con todos los abonos.');
    }

    public function test_el_pdf_sale_para_el_rango(): void
    {
        $this->guia(12345, '2026-09-10');

        $this->actingAs($this->admin)
            ->get(route('credito.rango', ['customer' => $this->cliente, 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_el_pdf_rechaza_un_rango_al_reves(): void
    {
        $this->actingAs($this->admin)
            ->get(route('credito.rango', ['customer' => $this->cliente, 'from' => '2026-09-30', 'to' => '2026-09-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_un_cliente_de_contado_no_tiene_estado_de_cuenta(): void
    {
        $contado = Customer::create(['name' => 'Contado', 'payment_condition' => Customer::PAYMENT_CASH]);

        $this->actingAs($this->admin)
            ->get(route('credito.rango', ['customer' => $contado, 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertNotFound();
    }

    public function test_un_cajero_no_lo_ve(): void
    {
        $cajero = User::create(['name' => 'Caj', 'username' => 'caj', 'email' => 'c@t.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->sede->id]);

        $this->actingAs($cajero)
            ->get(route('credito.rango', ['customer' => $this->cliente, 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertForbidden();
    }

    // ── En el panel ───────────────────────────────────────────────────

    public function test_el_panel_arma_el_enlace_con_las_fechas(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreditoPanel::class)
            ->set('customerId', $this->cliente->id)
            ->set('rangoDesde', '2026-09-01')
            ->set('rangoHasta', '2026-09-30')
            ->assertSee('Estado de cuenta por fechas')
            ->assertSee(route('credito.rango', ['customer' => $this->cliente->id, 'from' => '2026-09-01', 'to' => '2026-09-30']));
    }

    public function test_el_panel_avisa_si_las_fechas_estan_al_reves(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreditoPanel::class)
            ->set('customerId', $this->cliente->id)
            ->set('rangoDesde', '2026-09-30')
            ->set('rangoHasta', '2026-09-01')
            ->assertSee('La fecha final no puede ser anterior a la inicial.')
            ->assertDontSee(route('credito.rango', ['customer' => $this->cliente->id, 'from' => '2026-09-30', 'to' => '2026-09-01']));
    }
}
