<?php

namespace Tests\Feature\Guides;

use App\Livewire\Branches\BranchIndex;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\InvoiceTax;
use App\Models\User;
use App\Services\BarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Impresoras de matriz de puntos (Epson TM-U220 y similares).
 *
 * El cabezal de 9 agujas imprime con tan poca resolución que la letra chica y
 * en peso normal que en térmica se lee perfecto salía deshecha: solo se leía
 * lo que ya iba grande y en negrita. Y el rollo es de 76 mm, con 63,4 mm
 * imprimibles: el diseño de 80 salía cortado a la derecha.
 */
class ImpresoraMatrizTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;
    private Invoice $guia;

    protected function setUp(): void
    {
        parent::setUp();

        CompanySetting::instance();

        $this->sj = Branch::create([
            'name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001',
            'is_active' => true, 'receipt_paper_width' => 76, 'receipt_printer' => Branch::IMPRESORA_MATRIZ,
        ]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        $this->guia = Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta Solano', 'sender_identification' => '109870654', 'sender_phone' => '8888-1111',
            'recipient_name' => 'José Fernández', 'recipient_phone' => '7777-2222',
            'recipient_identification_type' => '01', 'recipient_identification' => '112340567',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 130, 'total' => 1130,
            'payment_method' => 'cash',
            'created_by' => $this->admin->id,
        ])->fresh();

        $this->guia->items()->create(['package_code' => 'PKG-1', 'description' => 'Repuestos', 'weight' => 2.5, 'price' => 1000]);

        InvoiceTax::create([
            'invoice_id' => $this->guia->id, 'name' => 'IVA general', 'percent' => 13,
            'hacienda_code' => '08', 'amount' => 130,
        ]);
    }

    private function html(string $ruta, array $query = []): string
    {
        return $this->actingAs($this->admin)
            ->get(route($ruta, array_merge(['invoice' => $this->guia], $query)))
            ->assertOk()
            ->getContent();
    }

    public function test_el_recibo_de_una_sede_con_matriz_va_en_negrita_y_letra_sin_serifas(): void
    {
        $html = $this->html('invoices.recibo');

        $this->assertStringContainsString('font-family: Tahoma', $html);
        $this->assertStringContainsString('font-weight: bold', $html);
    }

    /** Diseñado a lo que imprime el cabezal, no al ancho del papel. */
    public function test_en_rollo_de_76_se_disena_a_los_63_mm_imprimibles(): void
    {
        $html = $this->html('invoices.recibo');

        $this->assertStringContainsString('size: 76mm auto', $html);
        $this->assertStringContainsString('width: 63mm', $html);
    }

    public function test_una_sede_termica_sigue_como_antes(): void
    {
        $this->sj->update(['receipt_printer' => Branch::IMPRESORA_TERMICA]);

        $html = $this->html('invoices.recibo');

        $this->assertStringNotContainsString('Tahoma', $html);
        $this->assertStringContainsString('Courier New', $html);
    }

    /** Para probar la otra impresora sin tocar la sede. */
    public function test_el_tipo_de_impresora_se_puede_forzar_por_url(): void
    {
        $this->assertStringNotContainsString('Tahoma', $this->html('invoices.recibo', ['impresora' => 'termica']));

        $this->sj->update(['receipt_printer' => Branch::IMPRESORA_TERMICA]);

        $this->assertStringContainsString('Tahoma', $this->html('invoices.recibo', ['impresora' => 'matriz']));
    }

    public function test_un_tipo_de_impresora_desconocido_cae_a_termica(): void
    {
        $this->sj->forceFill(['receipt_printer' => 'laser'])->save();

        $this->assertSame(Branch::IMPRESORA_TERMICA, $this->sj->fresh()->receiptPrinterType());
        $this->assertStringNotContainsString('Tahoma', $this->html('invoices.recibo'));
    }

    public function test_la_etiqueta_en_matriz_lleva_barras_mas_altas_y_letra_legible(): void
    {
        $esperado = app(BarcodeService::class)->svg($this->guia->code, alto: 90, modulo: 2);

        $html = $this->html('invoices.etiqueta');

        $this->assertStringContainsString($esperado, $html);
        $this->assertStringContainsString('font-family: Tahoma', $html);
        $this->assertStringContainsString('width: 63mm', $html);
    }

    /**
     * El POR COBRAR iba en blanco sobre negro. El navegador no imprime el
     * fondo y el texto blanco salía en un gris que la de impacto convierte en
     * puntos sueltos.
     */
    public function test_el_por_cobrar_en_matriz_no_depende_de_un_fondo_negro(): void
    {
        $html = $this->html('invoices.etiqueta');

        $this->assertMatchesRegularExpression('/\.cobrar\s*\{\s*background:\s*none;\s*color:\s*#000;/', $html);
    }

    public function test_la_factura_en_rollo_lleva_los_datos_fiscales(): void
    {
        ElectronicInvoice::create([
            'branch_id' => $this->sj->id,
            'invoice_id' => $this->guia->id,
            'clave' => '50601082600310123456700100001010000000001100000001',
            'consecutivo' => '00100001010000000001',
            'document_type' => '04',
            'security_code' => '10000001',
            'environment' => 'sandbox',
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'sub_total' => 1000, 'total_tax' => 130, 'total_discount' => 0, 'total_other_charges' => 0,
            'total' => 1130,
            'status' => 'accepted',
            'issued_at' => now(),
            'send_attempts' => 0,
        ]);

        $html = $this->html('invoices.factura');

        foreach ([
            $this->guia->code,
            '109870654',                  // identificación del remitente
            '112340567',                  // y del receptor
            'Repuestos',
            '1,000.00',                   // precio del bulto
            'IVA general (13.00%)',
            '₡1,130.00',
            '00100001010000000001',       // consecutivo
            '50601082600310123456700100001010000000001100000001', // clave
        ] as $dato) {
            $this->assertStringContainsString($dato, $html);
        }

        // Mismo perfil que el recibo: es la misma impresora.
        $this->assertStringContainsString('font-family: Tahoma', $html);
        $this->assertStringContainsString('width: 63mm', $html);
    }

    /** La de A4 sigue a mano para descargar o mandar por correo. */
    public function test_la_factura_en_rollo_ofrece_la_de_a4(): void
    {
        $this->assertStringContainsString(route('invoices.pdf', $this->guia), $this->html('invoices.factura'));
    }

    /** La factura no es el recibo: no suma copias a la bitácora de reimpresión. */
    public function test_imprimir_la_factura_no_cuenta_como_reimpresion_del_recibo(): void
    {
        $this->html('invoices.factura');

        $this->assertSame(0, $this->guia->printLogs()->count());
    }

    public function test_un_repartidor_no_imprime_la_factura_de_otro(): void
    {
        $ajeno = User::create([
            'name' => 'Randall', 'username' => 'randall', 'email' => 'r@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_REPARTIDOR, 'is_active' => true,
        ]);

        $this->actingAs($ajeno)
            ->get(route('invoices.factura', $this->guia))
            ->assertForbidden();
    }

    public function test_el_listado_imprime_la_factura_en_rollo(): void
    {
        $html = $this->actingAs($this->admin)->get(route('invoices.index'))->getContent();

        $this->assertStringContainsString(route('invoices.factura', $this->guia), $html);
    }

    public function test_la_sede_guarda_el_tipo_de_impresora_y_el_rollo_de_76(): void
    {
        Livewire::actingAs($this->admin)
            ->test(BranchIndex::class)
            ->call('edit', $this->lim->id)
            ->assertSet('receipt_printer', Branch::IMPRESORA_TERMICA)
            ->set('receipt_printer', Branch::IMPRESORA_MATRIZ)
            ->set('receipt_paper_width', 76)
            ->call('save')
            ->assertHasNoErrors();

        $lim = $this->lim->fresh();

        $this->assertTrue($lim->imprimeEnMatriz());
        $this->assertSame(76, $lim->receiptPaperWidthMm());
    }

    public function test_la_sede_rechaza_un_tipo_de_impresora_inventado(): void
    {
        Livewire::actingAs($this->admin)
            ->test(BranchIndex::class)
            ->call('edit', $this->lim->id)
            ->set('receipt_printer', 'laser')
            ->call('save')
            ->assertHasErrors('receipt_printer');
    }
}
