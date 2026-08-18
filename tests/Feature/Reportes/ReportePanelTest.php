<?php

namespace Tests\Feature\Reportes;

use App\Livewire\Reportes\ReportePanel;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportePanelTest extends TestCase
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

    private function guia(array $extra = []): Invoice
    {
        return Invoice::create(array_merge([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id,
            'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'R', 'recipient_name' => 'D',
            'subtotal' => 10000, 'discount_amount' => 0, 'tax_total' => 1300, 'total' => 11300,
            'payment_method' => 'cash',
            'created_by' => $this->admin->id,
        ], $extra))->fresh();
    }

    private function panel()
    {
        return Livewire::actingAs($this->admin)->test(ReportePanel::class);
    }

    public function test_los_ocho_reportes_del_requisito_estan_disponibles(): void
    {
        $this->assertCount(8, ReportePanel::REPORTES);

        foreach (array_keys(ReportePanel::REPORTES) as $reporte) {
            $this->panel()->set('reporte', $reporte)->assertOk();
        }
    }

    public function test_guias_por_estado_agrupa_y_suma(): void
    {
        $this->guia();
        $this->guia();
        $this->guia(['status' => Invoice::STATUS_DELIVERED, 'delivered_at' => now()]);

        $this->panel()
            ->set('reporte', 'estados')
            ->assertSee('Recibido')
            ->assertSee('Entregado')
            ->assertSee('₡33,900.00'); // total de las tres
    }

    public function test_ventas_de_contado_se_desglosan_por_medio(): void
    {
        $this->guia(['payment_method' => 'cash']);
        $this->guia(['payment_method' => 'card']);

        $this->panel()
            ->set('reporte', 'ventas')
            ->assertSee('Efectivo')
            ->assertSee('Tarjeta');
    }

    /** Las guías a crédito no son venta de contado. */
    public function test_las_guias_a_credito_no_entran_en_ventas_de_contado(): void
    {
        $this->guia(['payment_method' => 'cash']);
        $this->guia(['sale_condition' => Invoice::SALE_CREDIT, 'total' => 99999]);

        $this->panel()
            ->set('reporte', 'ventas')
            ->assertDontSee('99,999');
    }

    public function test_el_volumen_por_ruta_usa_los_prefijos(): void
    {
        $this->guia();
        $this->guia();

        $this->panel()
            ->set('reporte', 'rutas')
            ->assertSee('SJ → LIM');
    }

    /** El indicador de servicio: no cuántas se movieron, sino cuánto tardaron. */
    public function test_el_tiempo_de_entrega_promedia_en_dias(): void
    {
        $guia = $this->guia(['status' => Invoice::STATUS_DELIVERED]);
        $guia->forceFill([
            'created_at'   => now()->subDays(3),
            'delivered_at' => now(),
        ])->save();

        $this->panel()
            ->set('reporte', 'entrega')
            ->assertSee('SJ → LIM')
            ->assertSee('3.0');
    }

    public function test_el_filtro_de_sede_acota_los_resultados(): void
    {
        $this->guia(); // SJ → LIM
        $otra = Branch::create(['name' => 'Cartago', 'prefix' => 'CAR', 'sucursal_code' => '003', 'terminal_code' => '00001', 'is_active' => true]);
        $this->guia(['pickup_branch_id' => $otra->id, 'delivery_branch_id' => $otra->id]);

        $this->panel()
            ->set('reporte', 'rutas')
            ->set('branchId', $otra->id)
            ->assertSee('CAR → CAR')
            ->assertDontSee('SJ → LIM');
    }

    public function test_el_filtro_de_fechas_deja_fuera_lo_anterior(): void
    {
        $vieja = $this->guia();
        $vieja->forceFill(['created_at' => now()->subMonths(3)])->save();

        $this->panel()
            ->set('reporte', 'estados')
            ->set('from', now()->startOfMonth()->toDateString())
            ->assertSee('No hay datos');
    }

    public function test_un_periodo_sin_datos_lo_dice_en_vez_de_salir_vacio(): void
    {
        $this->panel()
            ->set('reporte', 'estados')
            ->assertSee('No hay datos para el período');
    }
}
