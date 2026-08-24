<?php

namespace Tests\Feature\Roles;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rol Despachador: arma el camión y nada más.
 *
 * Es la persona de bodega. Entra a los cierres de envío —los prepara, los
 * despacha y recibe los que llegan— pero no cobra, no crea guías y no toca la
 * configuración.
 */
class RolDespachadorTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private User $despachador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);

        $this->despachador = User::create(['name'=>'Carlos Bodega','username'=>'carlos','email'=>'c@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_DESPACHADOR,'is_active'=>true,'branch_id'=>$this->sj->id]);
    }

    public function test_el_rol_existe_y_tiene_su_etiqueta(): void
    {
        $this->assertArrayHasKey(User::ROLE_DESPACHADOR, User::ROLES);
        $this->assertSame('Despachador', $this->despachador->roleLabel());
        $this->assertTrue($this->despachador->isDespachador());
    }

    public function test_tiene_su_propio_color(): void
    {
        $this->assertArrayHasKey(User::ROLE_DESPACHADOR, User::ROLE_BADGE_CLASSES);
        $this->assertCount(count(User::ROLES), array_unique(User::ROLE_BADGE_CLASSES));
    }

    // ── Lo que sí puede ───────────────────────────────────────────────

    public function test_entra_a_los_cierres_de_envio(): void
    {
        $this->actingAs($this->despachador)->get(route('dispatches.index'))->assertOk();
    }

    public function test_ve_los_cierres_en_el_menu(): void
    {
        $this->actingAs($this->despachador)
            ->get(route('dashboard'))
            ->assertSee(route('dispatches.index'));
    }

    // ── Lo que NO puede ───────────────────────────────────────────────

    public function test_no_cobra_ni_abre_caja(): void
    {
        $this->assertFalse($this->despachador->puedeOperarCaja());
        $this->actingAs($this->despachador)->get(route('caja.index'))->assertForbidden();
    }

    public function test_no_crea_guias(): void
    {
        $this->actingAs($this->despachador)->get(route('invoices.create'))->assertForbidden();
    }

    public function test_no_entra_a_la_configuracion(): void
    {
        $this->assertFalse($this->despachador->puedeConfigurar());

        foreach (['branches.index', 'users.index', 'settings.company', 'rates.index'] as $ruta) {
            $this->actingAs($this->despachador)->get(route($ruta))->assertForbidden();
        }
    }

    public function test_no_ve_credito_ni_reportes(): void
    {
        foreach (['credito.index', 'reportes.index', 'customers.index'] as $ruta) {
            $this->actingAs($this->despachador)->get(route($ruta))->assertForbidden();
        }
    }

    public function test_el_menu_no_le_muestra_lo_que_no_puede_usar(): void
    {
        $this->actingAs($this->despachador)
            ->get(route('dashboard'))
            ->assertDontSee(route('caja.index'))
            ->assertDontSee(route('users.index'));
    }

    // ── Su alcance ────────────────────────────────────────────────────

    /** Despacha en una sede concreta: necesita tenerla asignada. */
    public function test_necesita_una_sede_asignada(): void
    {
        $this->assertContains(User::ROLE_DESPACHADOR, User::ROLES_CON_SEDE);
    }

    /** Y solo ve lo de esa sede, igual que un cajero. */
    public function test_solo_ve_lo_de_su_sede(): void
    {
        $this->assertTrue($this->despachador->limitadoASuSede());
    }

    // ── Los demás roles no cambian ────────────────────────────────────

    public function test_el_cajero_y_el_administrador_siguen_despachando(): void
    {
        $cajero = User::create(['name'=>'Ana','username'=>'ana','email'=>'a@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$this->sj->id]);
        $admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'ad@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_ADMIN,'is_active'=>true]);

        $this->assertTrue($cajero->puedeDespachar());
        $this->assertTrue($admin->puedeDespachar());

        $this->actingAs($cajero)->get(route('dispatches.index'))->assertOk();
        $this->actingAs($admin)->get(route('dispatches.index'))->assertOk();
    }

    public function test_el_repartidor_no_despacha(): void
    {
        $r = User::create(['name'=>'R','username'=>'r','email'=>'r@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_REPARTIDOR,'is_active'=>true]);

        $this->assertFalse($r->puedeDespachar());
        $this->actingAs($r)->get(route('dispatches.index'))->assertForbidden();
    }
}
