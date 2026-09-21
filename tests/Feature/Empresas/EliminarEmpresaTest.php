<?php

namespace Tests\Feature\Empresas;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CompanyEraser;
use App\Services\CompanyProvisioner;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Borrar una empresa que ya operó.
 *
 * Es para deshacer una empresa cargada por error, no para dar de baja a un
 * cliente —a ese se le suspende—. Pero si se ofrece, tiene que funcionar de
 * verdad: borrar la fila de `companies` a secas aborta a mitad de camino,
 * porque unas pocas tablas apuntan a sedes y usuarios con RESTRICT y MySQL no
 * promete en qué orden ejecuta las cascadas.
 */
class EliminarEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private function empresaQueYaOpero(): Company
    {
        $empresa = app(CompanyProvisioner::class)->crear([
            'name' => 'Transportes López',
            'admin_name' => 'Ana', 'admin_email' => 'ana@lopez.cr',
            'admin_username' => null, 'admin_password' => 'clave-inicial',
            'branch_name' => 'San José', 'branch_prefix' => 'SJ',
        ]);

        CompanyContext::para($empresa, function () {
            $sede = Branch::first();
            $destino = Branch::create([
                'name' => 'Limón', 'prefix' => 'LIM',
                'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true,
            ]);

            Invoice::create([
                'code' => 'SJ-LIM-00001',
                'status' => Invoice::STATUS_PENDING,
                'pickup_branch_id' => $sede->id,
                'delivery_branch_id' => $destino->id,
                'sender_name' => 'Marta', 'recipient_name' => 'Jose',
                'total' => 3500,
                'created_by' => User::where('role', User::ROLE_ADMIN)->value('id'),
            ]);
        });

        return $empresa;
    }

    public function test_se_lleva_las_guias_las_sedes_y_los_usuarios(): void
    {
        $empresa = $this->empresaQueYaOpero();

        app(CompanyEraser::class)->borrar($empresa);

        $this->assertFalse(Company::whereKey($empresa->id)->exists());

        CompanyContext::sinAlcance(function () use ($empresa) {
            $this->assertSame(0, Invoice::where('company_id', $empresa->id)->count());
            $this->assertSame(0, Branch::where('company_id', $empresa->id)->count());
            $this->assertSame(0, User::where('company_id', $empresa->id)->count());
        });
    }

    /** Lo de las demás empresas no se toca. */
    public function test_no_arrastra_a_las_otras_empresas(): void
    {
        $empresa = $this->empresaQueYaOpero();

        $otra = app(CompanyProvisioner::class)->crear([
            'name' => 'Encomiendas del Sur',
            'admin_name' => 'Beto', 'admin_email' => 'beto@sur.cr',
            'admin_username' => null, 'admin_password' => 'clave-inicial',
            'branch_name' => 'Pérez Zeledón', 'branch_prefix' => 'PZ',
        ]);

        app(CompanyEraser::class)->borrar($empresa);

        $this->assertTrue(Company::whereKey($otra->id)->exists());
        CompanyContext::para($otra, function () {
            $this->assertSame(1, Branch::count());
            $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());
        });
    }
}
