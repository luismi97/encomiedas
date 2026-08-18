<?php

namespace Tests\Feature\Roles;

use App\Livewire\ActivityLogs\ActivityLogIndex;
use App\Livewire\Users\UserIndex;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El rol se mostraba con un condicional binario heredado de cuando solo había
 * dos: todo lo que no era administrador salía como «Repartidor», así que un
 * cajero se veía mal en tres pantallas distintas.
 */
class EtiquetaDeRolTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sede;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Ana Administradora', 'username' => 'ana', 'email' => 'ana@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function cajero(): User
    {
        return User::create([
            'name' => 'Yolanda Cajera', 'username' => 'yolanda', 'email' => 'yolanda@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
            'branch_id' => $this->sede->id,
        ]);
    }

    private function repartidor(): User
    {
        return User::create([
            'name' => 'Randall Repartidor', 'username' => 'randall', 'email' => 'randall@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_REPARTIDOR, 'is_active' => true,
        ]);
    }

    public function test_cada_rol_tiene_su_propia_etiqueta(): void
    {
        $this->assertSame('Administrador', $this->admin->roleLabel());
        $this->assertSame('Cajero', $this->cajero()->roleLabel());
        $this->assertSame('Repartidor', $this->repartidor()->roleLabel());
    }

    /** El bug: un cajero aparecía como «Repartidor» en el listado. */
    public function test_el_listado_muestra_cajero_y_no_repartidor(): void
    {
        $cajero = $this->cajero();

        $html = Livewire::actingAs($this->admin)->test(UserIndex::class)->html();

        // El nombre del cajero y la etiqueta «Cajero» tienen que estar.
        $this->assertStringContainsString('Yolanda Cajera', $html);
        $this->assertStringContainsString('Cajero', $html);

        // Y no debe aparecer «Repartidor» si no hay ninguno registrado.
        $this->assertStringNotContainsString('Repartidor', $html);
    }

    public function test_el_listado_distingue_los_tres_roles_a_la_vez(): void
    {
        $this->cajero();
        $this->repartidor();

        Livewire::actingAs($this->admin)
            ->test(UserIndex::class)
            ->assertSee('Administrador')
            ->assertSee('Cajero')
            ->assertSee('Repartidor');
    }

    public function test_la_bitacora_de_actividad_muestra_el_rol_correcto(): void
    {
        $cajero = $this->cajero();

        ActivityLog::create([
            'user_id' => $cajero->id,
            'action' => 'test',
            'description' => 'Acción de prueba del cajero',
        ]);

        $html = Livewire::actingAs($this->admin)->test(ActivityLogIndex::class)->html();

        $this->assertStringContainsString('Cajero', $html);
        $this->assertStringNotContainsString('Repartidor', $html);
    }

    public function test_la_barra_superior_muestra_el_rol_correcto(): void
    {
        $html = $this->actingAs($this->cajero())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('Cajero', $html);
        $this->assertStringNotContainsString('Repartidor', $html);
    }

    /** Cada rol tiene color propio: sin eso, dos roles se veían iguales. */
    public function test_cada_rol_tiene_su_color(): void
    {
        foreach (array_keys(User::ROLES) as $rol) {
            $this->assertArrayHasKey($rol, User::ROLE_BADGE_CLASSES,
                "Falta el color del rol «{$rol}».");
        }

        $this->assertCount(count(User::ROLES), array_unique(User::ROLE_BADGE_CLASSES),
            'Dos roles comparten color y no se distinguen a simple vista.');
    }
}
