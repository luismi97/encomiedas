<?php

namespace Tests\Feature\Ui;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El nombre que rotula el menú.
 *
 * Sale de la base y no de APP_NAME: el .env lo fija quien monta el servidor una
 * sola vez, y con varias empresas en el mismo sistema sería el mismo rótulo
 * para todas. Lo que el administrador escribe en Configuración es lo que su
 * gente tiene que ver arriba a la izquierda.
 */
class MarcaDeLaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Branch::firstOrCreate(['prefix' => 'SJ'], ['name'=>'San José','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);

        return User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);
    }

    private function configurar(array $datos): void
    {
        CompanySetting::instance()->forceFill($datos)->save();
    }

    public function test_el_menu_muestra_el_nombre_de_la_empresa(): void
    {
        $this->configurar(['name' => 'Transportes La Amistad S.A.', 'commercial_name' => null]);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertSee('Transportes La Amistad S.A.');
    }

    /** Misma regla del recibo: lo impreso y lo de pantalla, la misma empresa. */
    public function test_el_nombre_comercial_le_gana_a_la_razon_social(): void
    {
        $this->configurar([
            'name' => 'Inversiones Solano y Asociados S.A.',
            'commercial_name' => 'Encomiendas Solano',
        ]);

        $respuesta = $this->actingAs($this->admin())->get(route('dashboard'));

        $respuesta->assertSee('Encomiendas Solano');
        $respuesta->assertDontSee('Inversiones Solano y Asociados S.A.');
    }

    /**
     * Recién instalada, la empresa ya tiene nombre y todavía no tiene cédula ni
     * configuración fiscal: el menú no puede quedar en blanco por eso.
     */
    public function test_sin_configuracion_fiscal_cae_al_nombre_de_la_empresa(): void
    {
        $this->configurar(['name' => null, 'commercial_name' => null]);

        Company::query()->update(['name' => 'Encomiendas del Sur']);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertSee('Encomiendas del Sur');
    }

    /**
     * El superadministrador no está dentro de ninguna empresa.
     *
     * Importa porque `CompanySetting::instance()` LANZA cuando no hay empresa en
     * contexto y hay varias: rotular el menú con eso tumbaba la pantalla entera
     * en cuanto el sistema tuviera un segundo cliente.
     */
    public function test_el_superadministrador_no_revienta_con_varias_empresas(): void
    {
        $this->configurar(['name' => 'Empresa uno']);

        $otra = Company::create(['name' => 'Empresa dos', 'slug' => 'empresa-dos', 'is_active' => true]);
        CompanySetting::withoutGlobalScopes()->create([
            'company_id' => $otra->id, 'environment' => 'sandbox', 'name' => 'Empresa dos',
        ]);

        $super = User::create(['name'=>'Súper','username'=>'super','email'=>'s@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_SUPERADMIN,'is_active'=>true]);
        $super->forceFill(['company_id' => null])->save();

        $this->actingAs($super)
            ->get(route('superadmin.companies.index'))
            ->assertOk()
            ->assertSee(config('app.name'));
    }

    /** Un nombre largo no puede empujar el menú fuera del sidebar. */
    public function test_un_nombre_largo_se_recorta_pero_se_puede_leer_entero(): void
    {
        $largo = 'Transportes y Encomiendas de la Región Huetar Atlántica Sociedad Anónima';
        $this->configurar(['commercial_name' => $largo]);

        $html = $this->actingAs($this->admin())->get(route('dashboard'))->getContent();

        preg_match('/<span class="([^"]*)" data-test="marca-empresa"[^>]*title="([^"]*)"/', $html, $m);

        $this->assertStringContainsString('truncate', $m[1] ?? '',
            'El rótulo sin truncate desborda el sidebar con un nombre real.');
        $this->assertSame($largo, $m[2] ?? '',
            'El nombre completo tiene que quedar disponible al pasar el puntero.');
    }
}
