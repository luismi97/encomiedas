<?php

namespace Tests\Feature\Guides;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\PrintLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reimpresión controlada: cada copia queda registrada y la etiqueta lo dice.
 * Dos rótulos iguales sin marca es el fraude que esto evita — uno viaja pagado
 * y el otro no.
 */
class ReimpresionTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $guia;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        CompanySetting::instance();

        $sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true, 'receipt_paper_width' => 80]);
        $lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);

        $this->usuario = User::create([
            'name' => 'Cajera', 'username' => 'cajera', 'email' => 'cajera@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        $this->guia = Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $sj->id, 'delivery_branch_id' => $lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 3000, 'discount_amount' => 0, 'tax_total' => 390, 'total' => 3390,
            'created_by' => $this->usuario->id,
        ])->fresh();

        $this->guia->items()->create(['package_code' => 'PKG-1', 'weight' => 2, 'price' => 3000]);
    }

    private function imprimir(array $query = [])
    {
        return $this->actingAs($this->usuario)
            ->get(route('invoices.recibo', array_merge(['invoice' => $this->guia], $query)));
    }

    public function test_la_primera_impresion_no_se_marca_como_copia(): void
    {
        $this->imprimir()
            ->assertOk()
            ->assertDontSee('REIMPRESIÓN');

        $registro = PrintLog::firstOrFail();

        $this->assertSame(1, $registro->copy_number);
        $this->assertFalse($registro->esReimpresion());
    }

    public function test_la_segunda_sale_marcada_como_reimpresion(): void
    {
        $this->imprimir();

        $this->imprimir()
            ->assertOk()
            ->assertSee('REIMPRESIÓN')
            ->assertSee('COPIA 2');
    }

    public function test_cada_impresion_queda_registrada_con_su_autor(): void
    {
        $this->imprimir();
        $this->imprimir();
        $this->imprimir();

        $registros = PrintLog::orderBy('copy_number')->get();

        $this->assertCount(3, $registros);
        $this->assertSame([1, 2, 3], $registros->pluck('copy_number')->all());
        $this->assertSame($this->usuario->id, $registros->first()->user_id);
        $this->assertNotNull($registros->first()->ip);
    }

    /** La bitácora es evidencia: se escribe y no se toca. */
    public function test_el_registro_de_impresion_no_se_actualiza(): void
    {
        $this->imprimir();

        $this->assertNull(PrintLog::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', PrintLog::firstOrFail()->getAttributes());
    }

    public function test_el_ancho_sale_de_la_sede_de_origen(): void
    {
        $this->imprimir()->assertOk()->assertSee('size: 80mm', false);

        $this->assertSame(80, PrintLog::firstOrFail()->paper_width);
    }

    public function test_el_ancho_se_puede_forzar_por_url(): void
    {
        $this->imprimir(['ancho' => 58])->assertOk()->assertSee('size: 58mm', false);

        $this->assertSame(58, PrintLog::firstOrFail()->paper_width);
    }

    public function test_un_ancho_invalido_cae_al_predeterminado(): void
    {
        $this->imprimir(['ancho' => 999])->assertOk()->assertSee('size: 80mm', false);
    }

    public function test_la_etiqueta_lleva_el_qr_y_el_codigo(): void
    {
        $this->imprimir()
            ->assertOk()
            ->assertSee($this->guia->code)
            ->assertSee('data:image/png;base64', false)
            ->assertSee('Recibí conforme');
    }
}
