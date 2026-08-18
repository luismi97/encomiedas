<?php

namespace Tests\Feature\Roles;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bloquear rutas no alcanza: el cajero entra legítimamente al listado de guías
 * y a la caja, y ahí adentro no debe ver lo de otras sedes.
 */
class AislamientoPorSedeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sanJose;
    private Branch $limon;
    private User $cajeroSJ;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanJose = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->limon   = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);

        $this->cajeroSJ = User::create([
            'name' => 'Cajera SJ', 'username' => 'cajerasj', 'email' => 'sj@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
            'branch_id' => $this->sanJose->id,
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function guia(Branch $origen, Branch $destino): Invoice
    {
        return Invoice::withoutGlobalScopes()->create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $origen->id,
            'delivery_branch_id' => $destino->id,
            'sender_name' => 'R', 'recipient_name' => 'D',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1000,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_el_cajero_solo_ve_las_guias_de_su_sede(): void
    {
        $propia = $this->guia($this->sanJose, $this->limon);
        $ajena  = $this->guia($this->limon, $this->limon);

        $this->actingAs($this->cajeroSJ);

        $vistas = Invoice::pluck('id');

        $this->assertTrue($vistas->contains($propia->id));
        $this->assertFalse($vistas->contains($ajena->id));
    }

    /** Una guía que llega a su sede también es suya: ella la entrega. */
    public function test_el_cajero_ve_las_guias_que_llegan_a_su_sede(): void
    {
        $entrante = $this->guia($this->limon, $this->sanJose);

        $this->actingAs($this->cajeroSJ);

        $this->assertTrue(Invoice::pluck('id')->contains($entrante->id));
    }

    public function test_el_administrador_ve_todas_las_sedes(): void
    {
        $this->guia($this->sanJose, $this->limon);
        $this->guia($this->limon, $this->limon);

        $this->actingAs($this->admin);

        $this->assertSame(2, Invoice::count());
    }

    public function test_el_cajero_solo_ve_las_cajas_de_su_sede(): void
    {
        $propia = CashRegister::withoutGlobalScopes()->create(['branch_id' => $this->sanJose->id, 'name' => 'Caja SJ', 'is_active' => true]);
        $ajena  = CashRegister::withoutGlobalScopes()->create(['branch_id' => $this->limon->id, 'name' => 'Caja Limón', 'is_active' => true]);

        $this->actingAs($this->cajeroSJ);

        $vistas = CashRegister::pluck('id');

        $this->assertTrue($vistas->contains($propia->id));
        $this->assertFalse($vistas->contains($ajena->id));
    }

    public function test_el_cajero_no_ve_turnos_de_otra_sede(): void
    {
        $cajaAjena = CashRegister::withoutGlobalScopes()->create(['branch_id' => $this->limon->id, 'name' => 'Caja Limón', 'is_active' => true]);

        CashSession::withoutGlobalScopes()->create([
            'cash_register_id' => $cajaAjena->id, 'branch_id' => $this->limon->id,
            'opened_by' => $this->admin->id, 'opened_at' => now(), 'opening_float' => 10000,
        ]);

        $this->actingAs($this->cajeroSJ);

        $this->assertSame(0, CashSession::count());
    }

    public function test_el_cajero_no_ve_cierres_de_otra_ruta(): void
    {
        Dispatch::withoutGlobalScopes()->create([
            'code' => 'CIE-000001', 'origin_branch_id' => $this->limon->id,
            'destination_branch_id' => $this->limon->id, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->cajeroSJ);

        $this->assertSame(0, Dispatch::count());
    }

    /**
     * En cola y consola no hay usuario: el worker de Hacienda y los cron tienen
     * que ver todo, o dejarían de procesar lo de las demás sedes.
     */
    public function test_sin_usuario_autenticado_no_se_filtra_nada(): void
    {
        $this->guia($this->sanJose, $this->limon);
        $this->guia($this->limon, $this->limon);

        auth()->logout();

        $this->assertSame(2, Invoice::count());
    }

    /** El filtro va agrupado: no se escapa de los demás filtros de la consulta. */
    public function test_el_filtro_no_rompe_otras_condiciones(): void
    {
        $this->guia($this->sanJose, $this->limon)->forceFill(['status' => Invoice::STATUS_DELIVERED])->save();
        $this->guia($this->sanJose, $this->limon); // pendiente
        $this->guia($this->limon, $this->limon)->forceFill(['status' => Invoice::STATUS_DELIVERED])->save();

        $this->actingAs($this->cajeroSJ);

        // Solo la entregada de San José: si el orWhere se escapara, entraría
        // también la de Limón.
        $this->assertSame(1, Invoice::where('status', Invoice::STATUS_DELIVERED)->count());
    }

    public function test_un_cajero_sin_sede_no_activa_el_filtro(): void
    {
        // No debería existir —el formulario lo exige— pero si una fila vieja
        // quedó sin sede, es preferible que vea todo a que no vea nada.
        $sinSede = User::create([
            'name' => 'Sin sede', 'username' => 'sinsede', 'email' => 'sinsede@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
        ]);

        $this->guia($this->sanJose, $this->limon);
        $this->guia($this->limon, $this->limon);

        $this->actingAs($sinSede);

        $this->assertSame(2, Invoice::count());
    }
}
