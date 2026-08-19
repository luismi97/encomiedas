<?php

namespace Tests\Feature\Guides;

use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\PackageTypes\PackageTypeIndex;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\PackageType;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El «código de paquete» se reemplazó por el tipo de bulto.
 *
 * Ese código lo tenía que inventar el cajero renglón por renglón y nada en el
 * sistema lo usaba como llave: no se buscaba ni se rastreaba por él. La
 * identidad del bulto la da el código guía en la etiqueta; acá hacía falta
 * decir QUÉ es lo que se recibe.
 */
class TipoDeBultoTest extends TestCase
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

        Tax::create(['name' => 'IVA general', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    // ── Los tipos vienen configurados de fábrica ──────────────────────

    public function test_el_sistema_arranca_con_tipos_usables(): void
    {
        $nombres = PackageType::active()->pluck('name');

        $this->assertGreaterThanOrEqual(5, $nombres->count());
        foreach (['Paquete', 'Caja', 'Sobre', 'Herramienta'] as $esperado) {
            $this->assertTrue($nombres->contains($esperado), "Falta el tipo «{$esperado}».");
        }
    }

    public function test_hay_un_tipo_preseleccionado(): void
    {
        $this->assertNotNull(PackageType::porDefecto());
    }

    // ── El formulario ─────────────────────────────────────────────────

    private function formulario()
    {
        return Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta Solano')
            ->set('recipient_name', 'José Fernández')
            ->set('items.0.price', 3000);
    }

    /** El cajero ya no digita nada: el tipo llega puesto. */
    public function test_el_bulto_arranca_con_un_tipo_puesto(): void
    {
        Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->assertSet('items.0.package_type_id', PackageType::porDefecto()->id);
    }

    public function test_ya_no_se_pide_un_codigo_de_paquete(): void
    {
        $html = Livewire::actingAs($this->admin)->test(InvoiceForm::class)->html();

        $this->assertStringNotContainsString('Código de paquete', $html);
        $this->assertStringNotContainsString('package_code', $html);
        $this->assertStringContainsString('Tipo de bulto', $html);
    }

    public function test_se_guarda_el_tipo_elegido(): void
    {
        $sobre = PackageType::where('name', 'Sobre')->firstOrFail();

        $this->formulario()
            ->set('items.0.package_type_id', $sobre->id)
            ->call('save')
            ->assertHasNoErrors();

        $item = Invoice::firstOrFail()->items()->firstOrFail();

        $this->assertSame($sobre->id, $item->package_type_id);
        $this->assertSame('Sobre', $item->nombreDelBulto());
    }

    public function test_el_desplegable_solo_ofrece_los_tipos_activos(): void
    {
        PackageType::where('name', 'Herramienta')->update(['is_active' => false]);

        $html = Livewire::actingAs($this->admin)->test(InvoiceForm::class)->html();

        $this->assertStringContainsString('Caja', $html);
        $this->assertStringNotContainsString('Herramienta', $html);
    }

    // ── Dónde se imprime ──────────────────────────────────────────────

    public function test_el_tipo_aparece_en_la_etiqueta_del_paquete(): void
    {
        $caja = PackageType::where('name', 'Caja')->firstOrFail();

        $this->formulario()->set('items.0.package_type_id', $caja->id)->call('save');

        $this->actingAs($this->admin)
            ->get(route('invoices.etiqueta', Invoice::firstOrFail()))
            ->assertOk()
            ->assertSee('CAJA');
    }

    /** strtoupper() va por bytes: «Electrodoméstico» salía como ELECTRODOMéSTICO. */
    public function test_un_tipo_con_tilde_sale_bien_en_mayusculas(): void
    {
        $tipo = PackageType::create(['name' => 'Electrodoméstico grande', 'is_active' => true]);

        $this->formulario()->set('items.0.package_type_id', $tipo->id)->call('save');

        $this->actingAs($this->admin)
            ->get(route('invoices.etiqueta', Invoice::firstOrFail()))
            ->assertSee('ELECTRODOMÉSTICO GRANDE')
            ->assertDontSee('ELECTRODOMéSTICO');
    }

    /** El aviso es para quien carga el camión, no para quien digitó la guía. */
    public function test_un_tipo_fragil_avisa_en_la_etiqueta(): void
    {
        $fragil = PackageType::where('is_fragile', true)->firstOrFail();

        $this->formulario()->set('items.0.package_type_id', $fragil->id)->call('save');

        $this->actingAs($this->admin)
            ->get(route('invoices.etiqueta', Invoice::firstOrFail()))
            ->assertSee('FRÁGIL');
    }

    public function test_un_tipo_normal_no_avisa_de_nada(): void
    {
        $normal = PackageType::where('is_fragile', false)->firstOrFail();

        $this->formulario()->set('items.0.package_type_id', $normal->id)->call('save');

        $this->actingAs($this->admin)
            ->get(route('invoices.etiqueta', Invoice::firstOrFail()))
            ->assertDontSee('FRÁGIL');
    }

    public function test_el_recibo_del_cliente_nombra_el_bulto(): void
    {
        $sobre = PackageType::where('name', 'Sobre')->firstOrFail();

        $this->formulario()->set('items.0.package_type_id', $sobre->id)->call('save');

        $this->actingAs($this->admin)
            ->get(route('invoices.recibo', Invoice::firstOrFail()))
            ->assertSee('Sobre');
    }

    /** La línea del comprobante electrónico tiene que decir algo con sentido. */
    public function test_el_detalle_de_hacienda_usa_el_tipo(): void
    {
        $caja = PackageType::where('name', 'Caja')->firstOrFail();

        $this->formulario()->set('items.0.package_type_id', $caja->id)->call('save');

        $item = Invoice::firstOrFail()->items()->firstOrFail();

        $this->assertSame('Caja', $item->nombreDelBulto());
    }

    /** Los renglones viejos conservan el código con que se registraron. */
    public function test_un_bulto_viejo_sigue_mostrando_su_codigo(): void
    {
        $guia = Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1000,
            'created_by' => $this->admin->id,
        ]);

        $viejo = $guia->items()->create(['package_code' => 'PKG-0001-1', 'price' => 1000]);

        $this->assertSame('PKG-0001-1', $viejo->nombreDelBulto());
        $this->assertFalse($viejo->esFragil());
    }

    // ── La pantalla de configuración ──────────────────────────────────

    private function config()
    {
        return Livewire::actingAs($this->admin)->test(PackageTypeIndex::class);
    }

    public function test_se_agrega_un_tipo_nuevo(): void
    {
        $this->config()
            ->call('create')
            ->set('name', 'Llanta')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(PackageType::where('name', 'Llanta')->exists());
    }

    public function test_no_se_repite_el_nombre_de_un_tipo(): void
    {
        $this->config()
            ->call('create')
            ->set('name', 'Caja')
            ->call('save')
            ->assertHasErrors('name');
    }

    /** Borrarlo dejaría en blanco bultos de guías ya emitidas. */
    public function test_no_se_elimina_un_tipo_ya_usado(): void
    {
        $caja = PackageType::where('name', 'Caja')->firstOrFail();
        $this->formulario()->set('items.0.package_type_id', $caja->id)->call('save');

        $this->config()
            ->call('delete', $caja->id)
            ->assertSet('feedbackType', 'error')
            ->assertSee('Desactivalo');

        $this->assertNotNull(PackageType::find($caja->id));
    }

    public function test_un_tipo_sin_uso_si_se_elimina(): void
    {
        $herramienta = PackageType::where('name', 'Herramienta')->firstOrFail();

        $this->config()
            ->call('delete', $herramienta->id)
            ->assertSet('feedbackType', 'success');

        $this->assertNull(PackageType::find($herramienta->id));
    }

    /** Sin ningún tipo activo, el formulario de guías no tendría qué ofrecer. */
    public function test_no_se_desactiva_el_ultimo_tipo(): void
    {
        PackageType::where('name', '!=', 'Caja')->update(['is_active' => false]);
        $ultimo = PackageType::where('name', 'Caja')->firstOrFail();

        $this->config()
            ->call('toggleActive', $ultimo->id)
            ->assertSet('feedbackType', 'error')
            ->assertSee('único tipo activo');

        $this->assertTrue($ultimo->fresh()->is_active);
    }

    public function test_solo_el_administrador_configura_los_tipos(): void
    {
        $cajero = User::create([
            'name' => 'Ana', 'username' => 'ana', 'email' => 'ana@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
            'branch_id' => $this->sj->id,
        ]);

        $this->actingAs($cajero)->get(route('package-types.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('package-types.index'))->assertOk();
    }
}
