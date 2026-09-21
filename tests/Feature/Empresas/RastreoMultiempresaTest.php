<?php

namespace Tests\Feature\Empresas;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El portal público cuando el mismo código existe en dos empresas.
 *
 * El código guía es único por empresa, no por sistema: dos transportistas con
 * una sede «SJ» emiten los dos un SJ-LIM-00005. Por eso el QR del recibo lleva
 * la empresa en la URL. Lo que no se puede romper es el recibo ya impreso, ni
 * quien digita el código a mano.
 */
class RastreoMultiempresaTest extends TestCase
{
    use RefreshDatabase;

    private Company $otra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->otra = Company::create([
            'name' => 'Transportes Vecinos', 'slug' => 'transportes-vecinos', 'is_active' => true,
        ]);
    }

    private function guiaEn(?Company $empresa, string $codigo, string $destinatario): Invoice
    {
        $crear = function () use ($codigo, $destinatario) {
            $sede = Branch::create([
                'name' => 'Sede', 'prefix' => 'SJ',
                'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true,
            ]);

            $usuario = User::create([
                'name' => 'Cajero', 'username' => 'c' . uniqid(), 'email' => uniqid() . '@t.test',
                'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
            ]);

            return Invoice::create([
                'code' => $codigo,
                'status' => Invoice::STATUS_PENDING,
                'pickup_branch_id' => $sede->id,
                'delivery_branch_id' => $sede->id,
                'sender_name' => 'Remitente',
                'recipient_name' => $destinatario,
                'total' => 1000,
                'created_by' => $usuario->id,
            ]);
        };

        return $empresa ? CompanyContext::para($empresa, $crear) : $crear();
    }

    public function test_el_rastreo_con_empresa_muestra_la_guia_correcta(): void
    {
        $this->guiaEn(null, 'SJ-SJ-00001', 'Maria Gonzalez');
        $this->guiaEn($this->otra, 'SJ-SJ-00001', 'Pedro Vecino');

        $this->get(route('rastreo.empresa', [
            'empresa' => $this->otra->slug,
            'code' => 'SJ-SJ-00001',
        ]))->assertOk()->assertSee('Pedro V.')->assertDontSee('Maria G.');
    }

    /**
     * El código a secas, cuando existe en dos empresas, pregunta cuál.
     *
     * Elegir una de las dos sería mostrarle a alguien el paquete de otro.
     */
    public function test_el_codigo_repetido_pregunta_de_cual_empresa_es(): void
    {
        $this->guiaEn(null, 'SJ-SJ-00001', 'Maria Gonzalez');
        $this->guiaEn($this->otra, 'SJ-SJ-00001', 'Pedro Vecino');

        $this->get(route('rastreo.ver', ['code' => 'SJ-SJ-00001']))
            ->assertOk()
            ->assertSee('¿Con cuál empresa envió?')
            ->assertSee('Transportes Vecinos')
            ->assertDontSee('Pedro V.');
    }

    /** El recibo ya impreso, con la URL vieja, sigue funcionando. */
    public function test_el_codigo_a_secas_funciona_cuando_no_se_repite(): void
    {
        $this->guiaEn(null, 'SJ-SJ-00001', 'Maria Gonzalez');

        $this->get(route('rastreo.ver', ['code' => 'SJ-SJ-00001']))
            ->assertOk()
            ->assertSee('Maria G.');
    }

    public function test_una_empresa_inexistente_no_muestra_nada(): void
    {
        $this->guiaEn(null, 'SJ-SJ-00001', 'Maria Gonzalez');

        $this->get(route('rastreo.empresa', ['empresa' => 'no-existe', 'code' => 'SJ-SJ-00001']))
            ->assertOk()
            ->assertSee('No encontramos ninguna encomienda')
            ->assertDontSee('Maria G.');
    }

    /**
     * El portal es público: no hay sesión de la cual sacar la empresa, y aun así
     * el recorrido tiene que mostrar los nombres de las sedes.
     */
    public function test_el_portal_muestra_la_empresa_dueña_de_la_guia(): void
    {
        $this->guiaEn($this->otra, 'SJ-SJ-00099', 'Pedro Vecino');

        $this->get(route('rastreo.empresa', [
            'empresa' => $this->otra->slug, 'code' => 'SJ-SJ-00099',
        ]))->assertOk()->assertSee('Transportes Vecinos');
    }
}
