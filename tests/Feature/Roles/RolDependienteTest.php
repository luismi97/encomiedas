<?php

namespace Tests\Feature\Roles;

use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\Users\UserIndex;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rol Dependiente: atiende el mostrador y solo crea guías.
 *
 * No abre caja (sus guías de contado quedan esperando que un cajero las
 * cobre), no ve sumas de dinero ni reportes, y puede atender varias sedes que
 * el administrador le marca con casillas.
 */
class RolDependienteTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private Branch $pz;
    private User $admin;
    private User $dependiente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        $this->pz  = Branch::create(['name' => 'Pérez Zeledón', 'prefix' => 'PZ', 'sucursal_code' => '003', 'terminal_code' => '00001', 'is_active' => true]);
        Tax::create(['name' => 'IVA', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);

        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'a@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->dependiente = User::create(['name' => 'Laura Mostrador', 'username' => 'laura', 'email' => 'l@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_DEPENDIENTE, 'is_active' => true, 'branch_id' => $this->sj->id]);
        $this->dependiente->branches()->sync([$this->lim->id]);
    }

    private function guia(Branch $origen, Branch $destino, string $codigo): Invoice
    {
        return Invoice::create([
            'code' => $codigo, 'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $origen->id, 'delivery_branch_id' => $destino->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 130, 'total' => 1130,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_el_rol_existe_y_se_puede_asignar(): void
    {
        $this->assertArrayHasKey(User::ROLE_DEPENDIENTE, User::ROLES_ASIGNABLES);
        $this->assertSame('Dependiente', $this->dependiente->roleLabel());
        $this->assertArrayHasKey(User::ROLE_DEPENDIENTE, User::ROLE_BADGE_CLASSES);
    }

    // ── Lo que sí puede ───────────────────────────────────────────────

    public function test_crea_guias(): void
    {
        $this->actingAs($this->dependiente)->get(route('invoices.create'))->assertOk();
        $this->actingAs($this->dependiente)->get(route('invoices.index'))->assertSee(route('invoices.create'));
    }

    /** Sin caja: la guía de contado queda esperando que un cajero la cobre. */
    public function test_su_guia_de_contado_queda_esperando_caja(): void
    {
        Livewire::actingAs($this->dependiente)
            ->test(InvoiceForm::class)
            ->assertSet('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->pz->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.price', 1000)
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::withoutGlobalScopes()->firstOrFail();
        $this->assertTrue($guia->esperandoCaja());
        $this->assertSame($this->dependiente->id, $guia->created_by);
    }

    public function test_recibe_en_sus_sedes_extra_pero_no_en_otras(): void
    {
        $form = fn (Branch $origen) => Livewire::actingAs($this->dependiente)
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $origen->id)
            ->set('delivery_branch_id', $this->sj->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.price', 1000)
            ->call('save');

        $form($this->lim)->assertHasNoErrors();
        $form($this->pz)->assertHasErrors(['pickup_branch_id' => 'in']);
    }

    /** Ve las guías de su sede base y de las extra; no las de las demás. */
    public function test_ve_solo_las_guias_de_sus_sedes(): void
    {
        $this->guia($this->sj, $this->pz, 'SJ-PZ-1');
        $this->guia($this->pz, $this->lim, 'PZ-LIM-1');
        $this->guia($this->pz, $this->pz, 'PZ-PZ-1');

        $this->actingAs($this->dependiente);

        $this->assertEqualsCanonicalizing(['SJ-PZ-1', 'PZ-LIM-1'], Invoice::pluck('code')->all());
    }

    /** La guía que creó dice quién la atendió. */
    public function test_el_recibo_dice_quien_hizo_la_guia(): void
    {
        $guia = $this->guia($this->sj, $this->lim, 'SJ-LIM-1');
        $guia->forceFill(['created_by' => $this->dependiente->id])->save();

        $this->actingAs($this->dependiente)
            ->get(route('invoices.recibo', $guia))
            ->assertOk()
            ->assertSee('Atendido por: Laura Mostrador');
    }

    // ── Lo que no ─────────────────────────────────────────────────────

    public function test_no_abre_caja(): void
    {
        $this->assertFalse($this->dependiente->puedeCobrar());
        $this->actingAs($this->dependiente)->get(route('caja.index'))->assertForbidden();
    }

    public function test_no_ve_reportes_ni_sumas_de_dinero(): void
    {
        $this->actingAs($this->dependiente)->get(route('reportes.index'))->assertForbidden();
        $this->actingAs($this->dependiente)->get(route('credito.index'))->assertForbidden();
        $this->actingAs($this->dependiente)->get(route('invoices.export'))->assertForbidden();

        $this->actingAs($this->dependiente)
            ->get(route('invoices.index'))
            ->assertDontSee(route('invoices.export'))
            ->assertDontSee(route('reportes.index'))
            ->assertDontSee(route('caja.index'));
    }

    public function test_no_despacha_ni_configura(): void
    {
        $this->actingAs($this->dependiente)->get(route('dispatches.index'))->assertForbidden();
        $this->actingAs($this->dependiente)->get(route('users.index'))->assertForbidden();
    }

    // ── Configuración por el administrador ────────────────────────────

    public function test_el_administrador_le_marca_las_sedes_con_casillas(): void
    {
        Livewire::actingAs($this->admin)
            ->test(UserIndex::class)
            ->call('create')
            ->set('name', 'Pedro')
            ->set('email', 'p@t.test')
            ->set('password', 'secreto1')
            ->set('role', User::ROLE_DEPENDIENTE)
            ->set('branch_id', $this->sj->id)
            ->assertSee('Otras sedes que atiende')
            ->set('sedesExtra', [(string) $this->lim->id, (string) $this->pz->id])
            ->call('save')
            ->assertHasNoErrors();

        $pedro = User::where('email', 'p@t.test')->firstOrFail();
        $this->assertEqualsCanonicalizing([$this->sj->id, $this->lim->id, $this->pz->id], $pedro->sedesIds());
    }

    /** Si pasa a un rol de una sola sede (despachador), pierde las extra. */
    public function test_al_cambiar_de_rol_se_le_quitan_las_sedes_extra(): void
    {
        Livewire::actingAs($this->admin)
            ->test(UserIndex::class)
            ->call('edit', $this->dependiente->id)
            ->assertSet('sedesExtra', [(string) $this->lim->id])
            ->set('role', User::ROLE_DESPACHADOR)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertCount(0, $this->dependiente->fresh()->branches);
    }
}
