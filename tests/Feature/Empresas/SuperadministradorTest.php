<?php

namespace Tests\Feature\Empresas;

use App\Livewire\Superadmin\CompanyIndex;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El dueño del sistema y la puerta entre su panel y las empresas.
 *
 * Dos reglas sostienen el resto:
 *  - el superadministrador no opera ninguna empresa, porque no tendría con cuál
 *    de todas trabajar; para eso suplanta;
 *  - nadie de una empresa llega a su panel, que es donde están las demás.
 */
class SuperadministradorTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = new User([
            'name' => 'Dueño', 'username' => 'dueno', 'email' => 'dueno@sistema.test',
            'password' => bcrypt('password'), 'role' => User::ROLE_SUPERADMIN, 'is_active' => true,
        ]);
        $this->superadmin->sinEmpresa()->save();
    }

    private function empresa(string $nombre, string $correoAdmin, array $extra = []): Company
    {
        return app(CompanyProvisioner::class)->crear(array_merge([
            'name' => $nombre,
            'admin_name' => 'Admin de ' . $nombre,
            'admin_email' => $correoAdmin,
            'admin_username' => null,
            'admin_password' => 'clave-inicial',
            'branch_name' => 'Sede principal',
            'branch_prefix' => null,
        ], $extra));
    }

    public function test_el_superadministrador_entra_a_su_panel(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('superadmin.companies.index'))
            ->assertOk()
            ->assertSee('Empresas');
    }

    public function test_al_iniciar_sesion_va_al_panel_de_empresas(): void
    {
        $this->post('/login', ['login' => 'dueno@sistema.test', 'password' => 'password'])
            ->assertRedirect(route('superadmin.companies.index'));
    }

    /**
     * Sin empresa no hay pantalla de operación que tenga sentido.
     *
     * Antes de cortarlo acá, cada pantalla fallaba a su manera: la de guías las
     * mostraba TODAS mezcladas, la de caja reventaba buscando una sede que no
     * existe. Se manda al panel en vez de dar un 403 seco: el superadministrador
     * no hizo nada malo, solo entró por la puerta equivocada.
     */
    public function test_el_superadministrador_no_entra_a_las_pantallas_de_una_empresa(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('invoices.index'))
            ->assertRedirect(route('superadmin.companies.index'));
    }

    public function test_un_administrador_de_empresa_no_ve_el_panel(): void
    {
        $empresa = $this->empresa('Transportes López', 'ana@lopez.cr');

        $this->actingAs($empresa->admin())
            ->get(route('superadmin.companies.index'))
            ->assertForbidden();
    }

    /**
     * Suplantar: entrar a una empresa con la identidad de su administrador.
     *
     * Es el camino de soporte. Se inicia sesión de verdad —mismo rol, mismos
     * datos, mismas pantallas— en vez de inventar un modo «ver como» que cada
     * pantalla tendría que soportar.
     */
    public function test_el_superadministrador_puede_entrar_a_una_empresa_y_volver(): void
    {
        $empresa = $this->empresa('Transportes López', 'ana@lopez.cr');
        $admin = $empresa->admin();

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.companies.entrar', $empresa))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->assertSame($empresa->id, CompanyContext::actual()?->id);

        $this->post(route('superadmin.volver'))
            ->assertRedirect(route('superadmin.companies.index'));

        $this->assertAuthenticatedAs($this->superadmin);
    }

    public function test_suplantando_se_ve_la_pantalla_de_guias(): void
    {
        $empresa = $this->empresa('Transportes López', 'ana@lopez.cr');

        $this->actingAs($this->superadmin)->post(route('superadmin.companies.entrar', $empresa));

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Volver al panel');
    }

    /** Una empresa sin administrador no se puede suplantar: no hay a quién. */
    public function test_no_se_entra_a_una_empresa_sin_administrador(): void
    {
        $empresa = Company::create(['name' => 'Vacía', 'slug' => 'vacia', 'is_active' => true]);

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.companies.entrar', $empresa))
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($this->superadmin);
    }

    public function test_una_empresa_suspendida_no_puede_entrar(): void
    {
        $empresa = $this->empresa('Transportes López', 'ana@lopez.cr');
        $empresa->update(['is_active' => false]);

        $this->post('/login', ['login' => 'ana@lopez.cr', 'password' => 'clave-inicial'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_una_empresa_vencida_no_puede_entrar(): void
    {
        $empresa = $this->empresa('Transportes López', 'ana@lopez.cr');
        $empresa->update(['expires_on' => now()->subDay()]);

        $this->post('/login', ['login' => 'ana@lopez.cr', 'password' => 'clave-inicial'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /**
     * Suspender saca a quien ya estaba dentro.
     *
     * Solo bloquear el login dejaría operando —cobrando y emitiendo— a quien
     * tenía la sesión abierta cuando se le suspendió la cuenta.
     */
    public function test_suspender_corta_la_sesion_abierta(): void
    {
        $empresa = $this->empresa('Transportes López', 'ana@lopez.cr');
        $admin = $empresa->admin();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $empresa->update(['is_active' => false]);

        // actingAs otra vez para simular la petición SIGUIENTE: cada una
        // resuelve la empresa desde la base, y es ahí donde se entera de que la
        // suspendieron. Dentro de una misma petición el dato queda cacheado a
        // propósito, para no repetir la consulta en cada modelo que se toque.
        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_el_panel_suspende_y_reactiva(): void
    {
        $empresa = $this->empresa('Transportes López', 'ana@lopez.cr');

        Livewire::actingAs($this->superadmin)
            ->test(CompanyIndex::class)
            ->call('toggleActive', $empresa->id);

        $this->assertFalse($empresa->fresh()->is_active);
    }

    /**
     * Eliminar pide escribir el nombre.
     *
     * Es la única acción del panel que no se puede deshacer: se lleva las guías,
     * los comprobantes y los usuarios del cliente. Para un cliente que se va, lo
     * correcto es suspenderlo.
     */
    public function test_eliminar_exige_escribir_el_nombre(): void
    {
        $empresa = $this->empresa('Transportes López', 'ana@lopez.cr');

        Livewire::actingAs($this->superadmin)
            ->test(CompanyIndex::class)
            ->call('confirmDelete', $empresa->id)
            ->set('deleteConfirmation', 'Transportes Lopez') // sin tilde: no es el nombre
            ->call('delete');

        $this->assertTrue(Company::whereKey($empresa->id)->exists());

        Livewire::actingAs($this->superadmin)
            ->test(CompanyIndex::class)
            ->call('confirmDelete', $empresa->id)
            ->set('deleteConfirmation', 'Transportes López')
            ->call('delete');

        $this->assertFalse(Company::whereKey($empresa->id)->exists());
    }

    /**
     * El rol de superadministrador no se puede repartir desde una empresa.
     *
     * Si apareciera en el selector de Usuarios, cualquier administrador podría
     * fabricarse una cuenta sin empresa y ver las de todos los demás clientes.
     */
    public function test_un_administrador_no_puede_crear_un_superadministrador(): void
    {
        $empresa = $this->empresa('Transportes López', 'ana@lopez.cr');

        Livewire::actingAs($empresa->admin())
            ->test(\App\Livewire\Users\UserIndex::class)
            ->set('name', 'Colado')
            ->set('email', 'colado@lopez.cr')
            ->set('password', 'clave-larga')
            ->set('role', User::ROLE_SUPERADMIN)
            ->call('save')
            ->assertHasErrors(['role']);

        $this->assertFalse(User::withoutGlobalScopes()->where('email', 'colado@lopez.cr')->exists());
    }
}
