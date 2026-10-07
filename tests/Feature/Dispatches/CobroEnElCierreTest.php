<?php

namespace Tests\Feature\Dispatches;

use App\Livewire\Dispatches\DispatchIndex;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El cierre de envío dice qué guías van pagadas y cuáles hay que cobrar.
 *
 * Quien recibe el camión en destino no entrega un «por cobrar» sin cobrarlo, y
 * necesita saberlo y por cuánto sin abrir guía por guía.
 */
class CobroEnElCierreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Dispatch $cierre;
    private Invoice $pagada;
    private Invoice $porCobrar;
    private Invoice $credito;

    protected function setUp(): void
    {
        parent::setUp();

        $sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'a@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true, 'branch_id' => $sj->id]);

        $base = ['status' => Invoice::STATUS_PENDING, 'pickup_branch_id' => $sj->id,
            'delivery_branch_id' => $lim->id, 'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'created_by' => $this->admin->id];

        $this->pagada = Invoice::create($base + ['total' => 1000])->fresh();
        $this->porCobrar = Invoice::create($base + ['total' => 4520,
            'payment_timing' => Invoice::TIMING_COLLECT])->fresh();
        $this->credito = Invoice::create($base + ['total' => 2000,
            'sale_condition' => Invoice::SALE_CREDIT])->fresh();

        $this->cierre = Dispatch::create(['code' => 'CIE-000001', 'origin_branch_id' => $sj->id,
            'destination_branch_id' => $lim->id, 'driver_name' => 'Chofer', 'created_by' => $this->admin->id])->fresh();
    }

    public function test_las_guias_disponibles_ya_dicen_si_estan_pagadas(): void
    {
        Livewire::actingAs($this->admin)->test(DispatchIndex::class)
            ->call('open', $this->cierre->id)
            ->assertSee('Pagado')
            ->assertSee('Por cobrar ₡4,520.00')
            ->assertSee('Crédito');
    }

    public function test_dentro_del_cierre_cada_guia_dice_su_cobro(): void
    {
        foreach ([$this->pagada, $this->porCobrar, $this->credito] as $guia) {
            app(DispatchService::class)->agregarGuia($this->cierre, $guia);
        }

        Livewire::actingAs($this->admin)->test(DispatchIndex::class)
            ->call('open', $this->cierre->id)
            ->assertSee('Cobro')
            ->assertSeeInOrder([$this->pagada->code, 'Pagado'])
            ->assertSeeInOrder([$this->porCobrar->code, 'Por cobrar', '4,520.00'])
            ->assertSeeInOrder([$this->credito->code, 'Cr']);
    }

    /** El manifiesto viaja con el chofer: ahí tiene que estar, con el total a cobrar. */
    public function test_el_manifiesto_impreso_lo_muestra_con_el_total_a_cobrar(): void
    {
        foreach ([$this->pagada, $this->porCobrar, $this->credito] as $guia) {
            app(DispatchService::class)->agregarGuia($this->cierre, $guia);
        }

        $html = view('pdf.dispatch', [
            'dispatch' => $this->cierre->fresh()->load(['lines.invoice.items', 'lines.invoice.deliveryBranch', 'originBranch', 'destinationBranch', 'driver', 'creator', 'guides.items']),
            'company' => CompanySetting::instance(),
        ])->render();

        $this->assertStringContainsString('POR COBRAR ₡4,520.00', $html);
        $this->assertStringContainsString('Pagado', $html);
        $this->assertStringContainsString('Crédito', $html);
        $this->assertStringContainsString('Por cobrar en destino: 1 guía(s)', $html);
        // Al pie no van montos totales: ni el valor declarado ni lo por cobrar.
        $this->assertStringNotContainsString('valor declarado ₡', $html);
        $this->assertStringNotContainsString('guía(s) · ₡', $html);
    }

    /** Un «por cobrar» que ya se cobró deja de pedirse. */
    public function test_un_por_cobrar_ya_cobrado_sale_como_pagado(): void
    {
        $this->porCobrar->forceFill(['collected_at' => now()])->save();
        app(DispatchService::class)->agregarGuia($this->cierre, $this->porCobrar);

        Livewire::actingAs($this->admin)->test(DispatchIndex::class)
            ->call('open', $this->cierre->id)
            ->assertSeeInOrder([$this->porCobrar->code, 'Pagado'])
            ->assertDontSee('Por cobrar ₡4,520.00');
    }
}
