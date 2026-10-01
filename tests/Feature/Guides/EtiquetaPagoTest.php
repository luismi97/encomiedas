<?php

namespace Tests\Feature\Guides;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La etiqueta del paquete dice si el flete está pagado, con el desglose, el
 * medio de pago y el total. Quien entrega no tiene el recibo a mano.
 */
class EtiquetaPagoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Invoice $guia;

    protected function setUp(): void
    {
        parent::setUp();

        CompanySetting::instance();

        $sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        $this->guia = Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $sj->id, 'delivery_branch_id' => $lim->id,
            'sender_name' => 'Marta Solano', 'sender_identification' => '109870654',
            'recipient_name' => 'José Fernández',
            'recipient_identification_type' => '01', 'recipient_identification' => '112340567',
            'subtotal' => 1000, 'insurance_fee' => 50, 'discount_amount' => 100,
            'tax_total' => 123.5, 'total' => 1073.5,
            'payment_method' => 'sinpe',
            'created_by' => $this->admin->id,
        ])->fresh();
    }

    private function etiqueta(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('invoices.etiqueta', ['invoice' => $this->guia]))
            ->assertOk()
            ->getContent();
    }

    public function test_una_guia_pagada_lleva_desglose_medio_y_total_pagado(): void
    {
        $html = $this->etiqueta();

        $this->assertStringContainsString('PAGADO', $html);
        $this->assertStringContainsString('TOTAL PAGADO', $html);
        $this->assertStringContainsString('₡1,073.50', $html);
        $this->assertStringContainsString('SINPE Móvil', $html);
        $this->assertStringContainsString('Seguro', $html);
        $this->assertStringContainsString('-100.00', $html);
        $this->assertStringContainsString('123.50', $html);
    }

    public function test_un_por_cobrar_no_dice_pagado(): void
    {
        $this->guia->forceFill(['payment_timing' => Invoice::TIMING_COLLECT])->save();

        $html = $this->etiqueta();

        $this->assertStringContainsString('POR COBRAR', $html);
        $this->assertStringContainsString('Se paga al retirar', $html);
        $this->assertStringNotContainsString('TOTAL PAGADO', $html);
        $this->assertStringNotContainsString('SINPE Móvil', $html);
    }

    public function test_un_por_cobrar_ya_cobrado_sale_pagado(): void
    {
        $this->guia->forceFill(['payment_timing' => Invoice::TIMING_COLLECT, 'collected_at' => now()])->save();

        $this->assertStringContainsString('TOTAL PAGADO', $this->etiqueta());
    }

    public function test_esperando_caja_no_dice_pagado(): void
    {
        $this->guia->forceFill(['awaiting_cashier' => true])->save();

        $html = $this->etiqueta();

        $this->assertStringContainsString('PENDIENTE DE PAGO EN CAJA', $html);
        $this->assertStringNotContainsString('TOTAL PAGADO', $html);
    }

    public function test_a_credito_no_dice_pagado(): void
    {
        $this->guia->forceFill(['sale_condition' => Invoice::SALE_CREDIT])->save();

        $html = $this->etiqueta();

        $this->assertStringContainsString('A CRÉDITO', $html);
        $this->assertStringNotContainsString('TOTAL PAGADO', $html);
    }
}
