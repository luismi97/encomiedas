<?php

namespace Tests\Feature\Guides;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La etiqueta que se pega al paquete, distinta del recibo del cliente.
 *
 * Lleva el código de barras del código guía para poder escanearlo en recepción,
 * despacho y entrega, y NO lleva montos: queda a la vista de cualquiera que
 * manipule el bulto.
 */
class EtiquetaPaqueteTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function guia(int $bultos = 1): Invoice
    {
        $guia = Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta Solano', 'sender_phone' => '8888-1111',
            'recipient_name' => 'José Fernández', 'recipient_phone' => '7777-2222',
            'subtotal' => 3000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 3450,
            'created_by' => $this->admin->id,
        ]);

        for ($i = 1; $i <= $bultos; $i++) {
            $guia->items()->create([
                'package_code' => 'PKG-' . $i,
                'description' => 'Caja ' . $i,
                'weight' => 2.5,
                'price' => 1000,
            ]);
        }

        return $guia->fresh();
    }

    public function test_la_etiqueta_lleva_el_codigo_de_barras_del_codigo_guia(): void
    {
        $guia = $this->guia();

        $esperado = app(BarcodeService::class)->svg($guia->code, alto: 55, modulo: 2);

        $this->actingAs($this->admin)
            ->get(route('invoices.etiqueta', $guia))
            ->assertOk()
            ->assertSee($esperado, false)
            ->assertSee($guia->code);
    }

    /** El destino en grande: es lo que se lee al cargar el camión. */
    public function test_la_etiqueta_muestra_ruta_y_personas(): void
    {
        $guia = $this->guia();

        $this->actingAs($this->admin)
            ->get(route('invoices.etiqueta', $guia))
            ->assertSee('LIM')
            ->assertSee('Limón')
            ->assertSee('José Fernández')
            ->assertSee('7777-2222')
            ->assertSee('Marta Solano');
    }

    /** La etiqueta queda a la vista de cualquiera: sin montos. */
    public function test_la_etiqueta_no_muestra_cuanto_se_pago(): void
    {
        $guia = $this->guia();

        $html = $this->actingAs($this->admin)->get(route('invoices.etiqueta', $guia))->getContent();

        $this->assertStringNotContainsString('3450', $html);
        $this->assertStringNotContainsString('3,450', $html);
        $this->assertStringNotContainsString('Total', $html);
    }

    /** Tres paquetes se separan en bodega: cada uno necesita su etiqueta. */
    public function test_sale_una_etiqueta_por_bulto(): void
    {
        $guia = $this->guia(bultos: 3);

        $html = $this->actingAs($this->admin)->get(route('invoices.etiqueta', $guia))->getContent();

        $this->assertSame(3, substr_count($html, 'BULTO'));
        $this->assertStringContainsString('BULTO 1 DE 3', $html);
        $this->assertStringContainsString('BULTO 3 DE 3', $html);
        $this->assertSame(3, substr_count($html, '<svg'), 'Cada bulto lleva su código de barras.');
    }

    public function test_una_guia_sin_renglones_igual_imprime_una_etiqueta(): void
    {
        $guia = Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 0, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('invoices.etiqueta', $guia))
            ->assertOk()
            ->assertSee('BULTO 1 DE 1');
    }

    /** El ancho sale de la sede que imprime, igual que el recibo. */
    public function test_respeta_el_ancho_de_rollo_de_la_sede(): void
    {
        $this->sj->update(['receipt_paper_width' => 58]);
        $guia = $this->guia();

        $this->actingAs($this->admin)
            ->get(route('invoices.etiqueta', $guia))
            ->assertSee('size: 58mm auto', false);

        $this->actingAs($this->admin)
            ->get(route('invoices.etiqueta', $guia) . '?ancho=80')
            ->assertSee('size: 80mm auto', false);
    }

    /** Son dos documentos distintos y el mostrador tiene que poder elegir. */
    public function test_el_listado_ofrece_recibo_y_etiqueta_por_separado(): void
    {
        $guia = $this->guia();

        $html = $this->actingAs($this->admin)->get(route('invoices.index'))->getContent();

        $this->assertStringContainsString(route('invoices.recibo', $guia), $html);
        $this->assertStringContainsString(route('invoices.etiqueta', $guia), $html);
        $this->assertStringContainsString('Etiqueta del paquete', $html);
    }

    public function test_un_repartidor_no_imprime_la_etiqueta_de_otro(): void
    {
        $guia = $this->guia();

        $ajeno = User::create([
            'name' => 'Randall', 'username' => 'randall', 'email' => 'r@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_REPARTIDOR, 'is_active' => true,
        ]);

        $this->actingAs($ajeno)
            ->get(route('invoices.etiqueta', $guia))
            ->assertForbidden();
    }
}
