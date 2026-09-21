<?php

namespace Tests\Feature\Empresas;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cómo se resuelve la empresa activa, que es de lo que cuelga todo lo demás.
 */
class ContextoDeEmpresaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Preguntar por la empresa antes de que haya sesión no puede envenenar la
     * respuesta posterior.
     *
     * Es un caso real y costó encontrarlo: el arranque de la aplicación
     * construye un modelo —AppServiceProvider registra el observador de guías—
     * y eso consulta el contexto cuando todavía no hay usuario. Con el «no hay
     * empresa» de ese instante memorizado, TODA la petición seguía operando sin
     * empresa: el usuario entraba bien, veía su nombre y su sede, y el
     * aislamiento no filtraba absolutamente nada. En las pruebas de PHP no se
     * veía, porque actingAs() deja el usuario puesto desde el principio.
     */
    public function test_consultar_la_empresa_sin_sesion_no_la_deja_fijada(): void
    {
        $empresa = Company::create(['name' => 'Transportes López', 'slug' => 'lopez', 'is_active' => true]);

        $usuario = CompanyContext::para($empresa, fn () => User::create([
            'name' => 'Ana', 'username' => 'ana', 'email' => 'ana@lopez.cr',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]));

        CompanyContext::olvidar();

        // Igual que en el arranque: se instancia un modelo sin sesión, lo que
        // consulta el contexto.
        new Invoice();
        $this->assertNull(CompanyContext::actual());

        $this->actingAs($usuario);

        $this->assertSame(
            $empresa->id,
            CompanyContext::actual()?->id,
            'La consulta sin sesión dejó grabado que no había empresa.'
        );
    }

    /** Una vez con sesión, el valor sí se memoriza: se consulta en cada modelo. */
    public function test_con_sesion_la_empresa_se_resuelve_una_sola_vez(): void
    {
        $empresa = Company::create(['name' => 'Transportes López', 'slug' => 'lopez', 'is_active' => true]);

        $usuario = CompanyContext::para($empresa, fn () => User::create([
            'name' => 'Ana', 'username' => 'ana', 'email' => 'ana@lopez.cr',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]));

        $this->actingAs($usuario);

        $this->assertSame(CompanyContext::actual(), CompanyContext::actual());
    }

    /** El superadministrador no tiene empresa, y eso es un estado válido. */
    public function test_el_superadministrador_opera_sin_empresa(): void
    {
        $superadmin = new User([
            'name' => 'Dueño', 'username' => 'dueno', 'email' => 'dueno@sistema.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_SUPERADMIN, 'is_active' => true,
        ]);
        $superadmin->sinEmpresa()->save();

        $this->actingAs($superadmin);

        $this->assertNull(CompanyContext::actual());
    }

    /**
     * Las peticiones de Livewire pasan por el control de empresa.
     *
     * El nombre real de esa ruta es «default.livewire.update», con el prefijo
     * del perfil de configuración adelante. Filtrando por el nombre «livewire.»
     * no coincide, y entonces cada clic del panel se respondía con una
     * redirección: en el navegador, un botón que recarga la página y no hace
     * nada, sin error en ningún registro.
     */
    public function test_el_panel_del_superadministrador_puede_usar_livewire(): void
    {
        $superadmin = new User([
            'name' => 'Dueño', 'username' => 'dueno', 'email' => 'dueno@sistema.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_SUPERADMIN, 'is_active' => true,
        ]);
        $superadmin->sinEmpresa()->save();

        $peticion = \Illuminate\Http\Request::create('/livewire/update', 'POST');
        $peticion->setUserResolver(fn () => $superadmin);

        $respuesta = app(\App\Http\Middleware\RequiresCompany::class)
            ->handle($peticion, fn () => new \Illuminate\Http\Response('siguió'));

        $this->assertSame('siguió', $respuesta->getContent());
    }
}
