<?php

namespace Tests\Feature\Invoices;

use App\Livewire\Invoices\InvoiceIndex;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filtros del listado de guías: quién la registró, cómo se cobró, con qué
 * medio y si va a domicilio. Y la exportación trae lo mismo que la pantalla.
 */
class FiltrosDeGuiasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $cajera;
    private array $guias = [];

    protected function setUp(): void
    {
        parent::setUp();

        $sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'a@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true, 'branch_id' => $sj->id]);
        $this->cajera = User::create(['name' => 'Cajera Rosa', 'username' => 'rosa', 'email' => 'r@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $sj->id]);

        $nueva = function (string $clave, User $quien, array $extra) use ($sj, $lim) {
            $this->guias[$clave] = Invoice::create(array_merge([
                'status' => Invoice::STATUS_PENDING, 'pickup_branch_id' => $sj->id, 'delivery_branch_id' => $lim->id,
                'sender_name' => 'Remitente ' . $clave, 'recipient_name' => 'Destinatario ' . $clave,
                'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1000,
                'created_by' => $quien->id, 'payment_method' => 'cash',
                'sale_condition' => Invoice::SALE_CASH, 'payment_timing' => Invoice::TIMING_PREPAID,
            ]));
            // forceFill: varias columnas de cobro no son asignables en masa.
            $this->guias[$clave]->forceFill($extra)->save();
            $this->guias[$clave] = $this->guias[$clave]->fresh();
        };

        $nueva('contado', $this->admin, []);
        $nueva('sinpe', $this->cajera, ['payment_method' => 'sinpe']);
        $nueva('porcobrar', $this->cajera, ['payment_timing' => Invoice::TIMING_COLLECT]);
        $nueva('cobrada', $this->admin, ['payment_timing' => Invoice::TIMING_COLLECT, 'collected_at' => now()]);
        $nueva('credito', $this->admin, ['sale_condition' => Invoice::SALE_CREDIT]);
        $nueva('caja', $this->cajera, ['awaiting_cashier' => true]);
        $nueva('domicilio', $this->cajera, ['home_delivery' => true, 'delivery_address' => 'Barrio Escalante']);
    }

    /** Códigos de las guías que deja pasar el filtro, por su clave en el setUp. */
    private function quedan(array $filtros): array
    {
        $ids = Invoice::filtrar($filtros)->pluck('id')->all();

        return collect($this->guias)->filter(fn ($g) => in_array($g->id, $ids, true))->keys()->sort()->values()->all();
    }

    public function test_por_usuario_que_la_registro(): void
    {
        $this->assertSame(['caja', 'domicilio', 'porcobrar', 'sinpe'], $this->quedan(['creada_por' => $this->cajera->id]));
        $this->assertSame(['cobrada', 'contado', 'credito'], $this->quedan(['creada_por' => $this->admin->id]));
    }

    public function test_por_forma_de_cobro(): void
    {
        $this->assertSame(['porcobrar'], $this->quedan(['cobro' => 'por_cobrar']));
        $this->assertSame(['cobrada'], $this->quedan(['cobro' => 'cobrada_destino']));
        $this->assertSame(['credito'], $this->quedan(['cobro' => 'credito']));
        $this->assertSame(['caja'], $this->quedan(['cobro' => 'esperando_caja']));
        $this->assertSame(['contado', 'domicilio', 'sinpe'], $this->quedan(['cobro' => 'contado']));
    }

    public function test_por_medio_de_pago(): void
    {
        $this->assertSame(['sinpe'], $this->quedan(['medio' => 'sinpe']));
        $this->assertCount(7, $this->quedan(['medio' => 'inventado']), 'Un medio desconocido no filtra.');
    }

    public function test_los_filtros_se_combinan(): void
    {
        $this->assertSame(['domicilio'], $this->quedan(['creada_por' => $this->cajera->id, 'entrega' => 'domicilio']));
        $this->assertSame([], $this->quedan(['creada_por' => $this->admin->id, 'cobro' => 'por_cobrar']));
    }

    public function test_el_listado_filtra_y_muestra_quien_la_registro(): void
    {
        Livewire::actingAs($this->admin)->test(InvoiceIndex::class)
            ->assertSee('por Cajera Rosa')
            ->set('creadaPor', $this->cajera->id)
            ->set('cobro', 'por_cobrar')
            ->assertSee($this->guias['porcobrar']->code)
            ->assertDontSee($this->guias['sinpe']->code)
            ->assertDontSee($this->guias['contado']->code)
            ->call('limpiarFiltros')
            ->assertSet('creadaPor', null)
            ->assertSee($this->guias['contado']->code);
    }

    /** El PDF trae exactamente lo que se ve en pantalla. */
    public function test_la_exportacion_usa_los_mismos_filtros(): void
    {
        $this->actingAs($this->admin)
            ->get(route('invoices.export', ['creada_por' => $this->cajera->id, 'cobro' => 'por_cobrar']))
            ->assertOk();

        $html = view('pdf.invoices-report', [
            'invoices' => Invoice::filtrar(['creada_por' => $this->cajera->id, 'cobro' => 'por_cobrar'])->get(),
            'from' => '', 'to' => '', 'total' => 1000,
        ])->render();

        $this->assertStringContainsString($this->guias['porcobrar']->code, $html);
        $this->assertStringNotContainsString($this->guias['sinpe']->code, $html);
    }

    // ── Buscar por código no depende de los filtros ───────────────────

    /** La guía de ayer no aparecía porque el período arranca en «Hoy». */
    public function test_buscar_por_codigo_encuentra_una_guia_de_ayer(): void
    {
        $vieja = $this->guias['credito'];
        $vieja->forceFill(['created_at' => now()->subDay()])->save();

        Livewire::actingAs($this->admin)->test(InvoiceIndex::class)
            ->assertDontSee($vieja->code)
            ->set('search', $vieja->code)
            ->assertSee($vieja->code)
            ->assertSeeHtml('data-test="busqueda-sin-filtros"');
    }

    public function test_buscar_por_codigo_ignora_los_demas_filtros(): void
    {
        $guia = $this->guias['credito'];
        $guia->forceFill(['created_at' => now()->subMonths(3)])->save();

        $filtros = [
            'from' => today()->toDateString(), 'to' => today()->toDateString(),
            'status' => Invoice::STATUS_DELIVERED, 'cobro' => 'por_cobrar', 'medio' => 'sinpe',
            'creada_por' => $this->cajera->id, 'entrega' => 'domicilio',
        ];

        $this->assertSame(['credito'], $this->quedan($filtros + ['search' => $guia->code]));
        // La cola numérica también es buscar por guía.
        $cola = substr($guia->code, -5);
        $this->assertContains('credito', $this->quedan($filtros + ['search' => $cola]));
    }

    /** Por nombre sí se respetan: «Hoy» sigue mandando. */
    public function test_buscar_por_nombre_respeta_los_filtros(): void
    {
        $this->guias['credito']->forceFill(['created_at' => now()->subDay()])->save();
        $hoy = ['from' => today()->toDateString(), 'to' => today()->toDateString()];

        $this->assertSame([], $this->quedan($hoy + ['search' => 'Destinatario credito']));
        $this->assertFalse(Invoice::esBusquedaDeGuia('Marta Solano'));
    }

    /** A medio escribir no se sueltan los filtros: traería toda la historia. */
    public function test_un_numero_corto_no_suelta_los_filtros(): void
    {
        $this->assertFalse(Invoice::esBusquedaDeGuia('5'));
        $this->assertFalse(Invoice::esBusquedaDeGuia('05'));
        $this->assertFalse(Invoice::esBusquedaDeGuia('SJ'));
        $this->assertTrue(Invoice::esBusquedaDeGuia('005'));
        $this->assertTrue(Invoice::esBusquedaDeGuia('SJ-LIM'));
        $this->assertTrue(Invoice::esBusquedaDeGuia('sj-lim-00005'));
        $this->assertTrue(Invoice::esBusquedaDeGuia('P-SJ-7K3M9QX2'));
    }

    /** El cajero sigue viendo solo lo de su sede aunque busque sin filtros. */
    public function test_la_sede_del_cajero_sigue_mandando(): void
    {
        $alajuela = Branch::create(['name' => 'Alajuela', 'prefix' => 'ALA', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        $heredia = Branch::create(['name' => 'Heredia', 'prefix' => 'HER', 'sucursal_code' => '003', 'terminal_code' => '00001', 'is_active' => true]);
        $ajena = Invoice::create([
            'status' => Invoice::STATUS_PENDING, 'pickup_branch_id' => $alajuela->id, 'delivery_branch_id' => $heredia->id,
            'sender_name' => 'Otro', 'recipient_name' => 'Otra', 'subtotal' => 1000, 'discount_amount' => 0,
            'tax_total' => 0, 'total' => 1000, 'created_by' => $this->admin->id,
        ])->fresh();

        // Por el enlace de la fila y no por el código: el código buscado
        // aparece siempre en la propia casilla de búsqueda.
        Livewire::actingAs($this->cajera)->test(InvoiceIndex::class)
            ->set('search', $ajena->code)
            ->assertDontSeeHtml('href="' . route('invoices.show', $ajena) . '"');

        Livewire::actingAs($this->admin)->test(InvoiceIndex::class)
            ->set('search', $ajena->code)
            ->assertSeeHtml('href="' . route('invoices.show', $ajena) . '"');
    }
}
