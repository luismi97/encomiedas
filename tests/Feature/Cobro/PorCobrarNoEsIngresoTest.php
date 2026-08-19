<?php

namespace Tests\Feature\Cobro;

use App\Livewire\Reportes\ReportePanel;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Un flete por cobrar es contado, así que entraba entero en «Ventas de
 * contado» aunque nadie lo hubiera pagado: el reporte declaraba como ingreso
 * dinero que no estaba en ninguna gaveta.
 */
class PorCobrarNoEsIngresoTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        $this->admin = User::create(['name'=>'A','username'=>'a','email'=>'a@t.test','password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);
    }

    private function guia(array $attrs = []): Invoice
    {
        return Invoice::create(array_merge([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'payment_method' => 'cash',
            'subtotal' => 10000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 10000,
            'created_by' => $this->admin->id,
        ], $attrs))->fresh();
    }

    private function reporte(string $cual): array
    {
        return Livewire::actingAs($this->admin)
            ->test(ReportePanel::class)
            ->set('from', now()->subYear()->toDateString())
            ->set('to', now()->addDay()->toDateString())
            ->set('reporte', $cual)
            ->viewData('datos');
    }

    private function montoTotal(array $datos): float
    {
        return round((float) collect($datos['filas'])->sum('monto'), 2);
    }

    // ── Ventas de contado ─────────────────────────────────────────────

    /** El bug: se contaba como venta lo que nadie había pagado. */
    public function test_un_por_cobrar_pendiente_no_entra_en_ventas_de_contado(): void
    {
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT]);

        $this->assertSame(0.0, $this->montoTotal($this->reporte('ventas')),
            'Un flete sin cobrar no es una venta cobrada.');
    }

    public function test_una_guia_pagada_si_entra_en_ventas_de_contado(): void
    {
        $this->guia(['payment_timing' => Invoice::TIMING_PREPAID]);

        $this->assertSame(10000.0, $this->montoTotal($this->reporte('ventas')));
    }

    /** Y al cobrarse en destino, entra. */
    public function test_un_por_cobrar_ya_cobrado_si_entra(): void
    {
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT, 'collected_at' => now()]);

        $this->assertSame(10000.0, $this->montoTotal($this->reporte('ventas')));
    }

    public function test_una_guia_a_credito_tampoco_es_venta_de_contado(): void
    {
        $this->guia(['sale_condition' => Invoice::SALE_CREDIT]);

        $this->assertSame(0.0, $this->montoTotal($this->reporte('ventas')));
    }

    public function test_solo_lo_cobrado_suma_entre_varias_guias(): void
    {
        $this->guia(['payment_timing' => Invoice::TIMING_PREPAID]);                       // sí
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT]);                       // no
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT, 'collected_at' => now()]); // sí
        $this->guia(['sale_condition' => Invoice::SALE_CREDIT]);                          // no

        $this->assertSame(20000.0, $this->montoTotal($this->reporte('ventas')));
    }

    // ── Guías por estado ──────────────────────────────────────────────

    /** Lo pedido: ver por estado cuánto sigue sin cobrarse. */
    public function test_guias_por_estado_separa_lo_que_esta_por_cobrar(): void
    {
        $this->guia(['payment_timing' => Invoice::TIMING_PREPAID]);
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT]);

        $datos = $this->reporte('estados');
        $fila = collect($datos['filas'])->firstWhere('etiqueta', 'Recibido');

        $this->assertTrue($datos['conPorCobrar']);
        $this->assertSame(2, $fila['cantidad']);
        $this->assertSame(20000.0, $fila['monto'], 'El monto facturado sigue siendo el total.');
        $this->assertSame(10000.0, $fila['por_cobrar'], 'Pero la mitad todavía no entró.');
    }

    public function test_lo_ya_cobrado_no_figura_como_pendiente(): void
    {
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT, 'collected_at' => now()]);

        $fila = collect($this->reporte('estados')['filas'])->firstWhere('etiqueta', 'Recibido');

        $this->assertSame(0.0, $fila['por_cobrar']);
    }

    public function test_la_columna_por_cobrar_aparece_en_pantalla(): void
    {
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT]);

        Livewire::actingAs($this->admin)
            ->test(ReportePanel::class)
            ->set('from', now()->subYear()->toDateString())
            ->set('to', now()->addDay()->toDateString())
            ->set('reporte', 'estados')
            ->assertSee('Por cobrar');
    }

    // ── El reporte dedicado ───────────────────────────────────────────

    public function test_el_reporte_de_cobro_separa_recibido_de_prometido(): void
    {
        $this->guia(['payment_timing' => Invoice::TIMING_PREPAID]);
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT]);
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT, 'collected_at' => now()]);
        $this->guia(['sale_condition' => Invoice::SALE_CREDIT]);

        $filas = collect($this->reporte('cobro')['filas'])->keyBy('etiqueta');

        $this->assertSame(10000.0, $filas['Pagadas en origen']['monto']);
        $this->assertSame(10000.0, $filas['Por cobrar · ya cobradas']['monto']);
        $this->assertSame(10000.0, $filas['Por cobrar · pendientes']['monto']);
        $this->assertSame(10000.0, $filas['A crédito']['monto']);

        $this->assertSame('NO es dinero aún', $filas['Por cobrar · pendientes']['extra']);
        $this->assertSame('Dinero recibido', $filas['Pagadas en origen']['extra']);
    }

    // ── El PDF de exportación ─────────────────────────────────────────

    /** El total del PDF es lo facturado; lo por cobrar va aclarado aparte. */
    public function test_el_pdf_de_exportacion_separa_lo_que_no_entro(): void
    {
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT]);
        $this->guia(['payment_timing' => Invoice::TIMING_PREPAID]);

        // Contra la plantilla y no contra el binario: DomPDF comprime el texto
        // y buscar la frase dentro del PDF daría un falso negativo.
        $html = view('pdf.invoices-report', [
            'invoices'  => Invoice::with(['pickupBranch', 'deliveryBranch'])->get(),
            'from'      => '', 'to' => '',
            'total'     => 20000,
            'porCobrar' => 10000,
        ])->render();

        $this->assertStringContainsString('Total facturado', $html);
        $this->assertStringContainsString('no son dinero recibido', $html);
        $this->assertStringContainsString('10,000.00', $html);

        $this->actingAs($this->admin)->get(route('invoices.export'))->assertOk();
    }

    // ── Los scopes, que es donde vive la regla ────────────────────────

    public function test_el_scope_de_cobradas_excluye_lo_pendiente(): void
    {
        $pagada    = $this->guia(['payment_timing' => Invoice::TIMING_PREPAID]);
        $pendiente = $this->guia(['payment_timing' => Invoice::TIMING_COLLECT]);
        $cobrada   = $this->guia(['payment_timing' => Invoice::TIMING_COLLECT, 'collected_at' => now()]);

        $ids = Invoice::cobradas()->pluck('id');

        $this->assertTrue($ids->contains($pagada->id));
        $this->assertTrue($ids->contains($cobrada->id));
        $this->assertFalse($ids->contains($pendiente->id));
    }

    public function test_el_scope_de_pendientes_es_el_complemento(): void
    {
        $this->guia(['payment_timing' => Invoice::TIMING_PREPAID]);
        $pendiente = $this->guia(['payment_timing' => Invoice::TIMING_COLLECT]);
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT, 'collected_at' => now()]);
        // Una a crédito con timing collect no cuenta: su plata la rige el crédito.
        $this->guia(['payment_timing' => Invoice::TIMING_COLLECT, 'sale_condition' => Invoice::SALE_CREDIT]);

        $this->assertSame([$pendiente->id], Invoice::porCobrarPendientes()->pluck('id')->all());
    }
}
