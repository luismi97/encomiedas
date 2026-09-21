<?php

namespace Tests\Feature\Empresas;

use App\Livewire\Superadmin\CompanyIndex;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Denomination;
use App\Models\PackageType;
use App\Models\Tax;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dar de alta un cliente nuevo sin instalar nada.
 *
 * Es el punto del cambio: antes, un cliente nuevo era base nueva, despliegue
 * nuevo y un recorrido a mano por seis pantallas de configuración —y lo que se
 * olvidaba aparecía días después, como «no puedo abrir la caja»—. Estas pruebas
 * fijan qué significa «lista para operar», para que el alta no vuelva a dejar
 * huecos.
 */
class AltaDeEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private function datos(array $extra = []): array
    {
        return array_merge([
            'name'           => 'Transportes López',
            'legal_name'     => 'Transportes López S.A.',
            'identification' => '3101999888',
            'email'          => 'contacto@lopez.cr',
            'phone'          => '2222-3333',
            'expires_on'     => null,
            'notes'          => null,
            'admin_name'     => 'Ana López',
            'admin_email'    => 'ana@lopez.cr',
            'admin_username' => null,
            'admin_password' => 'clave-inicial',
            'branch_name'    => 'San José Centro',
            'branch_prefix'  => 'SJ',
        ], $extra);
    }

    private function superadmin(): User
    {
        $superadmin = new User([
            'name' => 'Dueño', 'username' => 'dueno', 'email' => 'dueno@sistema.test',
            'password' => bcrypt('password'), 'role' => User::ROLE_SUPERADMIN, 'is_active' => true,
        ]);
        $superadmin->sinEmpresa()->save();

        return $superadmin;
    }

    public function test_la_empresa_queda_lista_para_operar(): void
    {
        $empresa = app(CompanyProvisioner::class)->crear($this->datos());

        CompanyContext::para($empresa, function () {
            $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count(), 'Sin administrador nadie puede entrar.');
            $this->assertSame(1, Branch::count(), 'Una guía va de una sede a otra: sin sede no se puede crear ninguna.');
            $this->assertSame(1, CashRegister::count(), 'Sin caja no se cobra.');
            $this->assertTrue(Tax::where('is_default', true)->exists(), 'Sin IVA por defecto hay que marcarlo guía por guía.');
            $this->assertGreaterThan(0, PackageType::count());
            $this->assertGreaterThan(0, Denomination::count(), 'Sin denominaciones el arqueo abre vacío.');
            $this->assertNotNull(CompanySetting::first());
        });
    }

    public function test_el_administrador_nuevo_puede_entrar(): void
    {
        app(CompanyProvisioner::class)->crear($this->datos());

        $this->post('/login', ['login' => 'ana@lopez.cr', 'password' => 'clave-inicial'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    /**
     * Lo que el sistema no puede inventar queda pendiente, y se sabe cuál es.
     *
     * El certificado y las credenciales de ATV son del contribuyente. Que la
     * empresa nazca sin ellos es correcto; lo que no puede pasar es que parezca
     * lista para facturar y falle al primer comprobante.
     */
    public function test_la_facturacion_electronica_queda_pendiente_y_dice_que_le_falta(): void
    {
        $empresa = app(CompanyProvisioner::class)->crear($this->datos());

        CompanyContext::para($empresa, function () {
            $faltantes = CompanySetting::instance()->faltantesParaFacturar();

            $this->assertNotEmpty($faltantes);
            $this->assertContains('El certificado digital (.p12)', $faltantes);
        });
    }

    /** Dos empresas pueden llamar «SJ» a su sede central. */
    public function test_dos_empresas_pueden_repetir_el_prefijo_de_sede(): void
    {
        app(CompanyProvisioner::class)->crear($this->datos());
        $segunda = app(CompanyProvisioner::class)->crear($this->datos([
            'name' => 'Encomiendas del Sur',
            'admin_email' => 'jefe@sur.cr',
        ]));

        CompanyContext::para($segunda, function () {
            $this->assertSame('SJ', Branch::first()->prefix);
        });
    }

    public function test_el_identificador_de_url_no_se_repite(): void
    {
        $primera = app(CompanyProvisioner::class)->crear($this->datos());
        $segunda = app(CompanyProvisioner::class)->crear($this->datos(['admin_email' => 'otra@lopez.cr']));

        $this->assertSame('transportes-lopez', $primera->slug);
        $this->assertNotSame($primera->slug, $segunda->slug);
    }

    /**
     * Si algo falla, no queda media empresa.
     *
     * El caso real es el correo repetido: revienta al crear el usuario, cuando
     * la empresa y la sede ya existen. Sin transacción quedan dando vueltas en
     * el panel, sin nadie que pueda entrar a ellas.
     */
    public function test_un_alta_que_falla_no_deja_nada_a_medias(): void
    {
        app(CompanyProvisioner::class)->crear($this->datos());
        $antes = Company::count();

        try {
            app(CompanyProvisioner::class)->crear($this->datos(['name' => 'Otra más']));
            $this->fail('El correo repetido tenía que reventar.');
        } catch (\Throwable) {
            // esperado
        }

        $this->assertSame($antes, Company::count());
    }

    public function test_el_panel_da_de_alta_una_empresa(): void
    {
        Livewire::actingAs($this->superadmin())
            ->test(CompanyIndex::class)
            ->set('name', 'Transportes López')
            ->set('admin_name', 'Ana López')
            ->set('admin_email', 'ana@lopez.cr')
            ->set('admin_password', 'clave-inicial')
            ->set('branch_name', 'San José Centro')
            ->set('branch_prefix', 'SJ')
            ->call('save')
            ->assertHasNoErrors();

        $empresa = Company::where('name', 'Transportes López')->first();

        $this->assertNotNull($empresa);
        $this->assertSame('ana@lopez.cr', $empresa->admin()?->email);
    }

    public function test_el_panel_rechaza_un_correo_ya_usado(): void
    {
        app(CompanyProvisioner::class)->crear($this->datos());

        Livewire::actingAs($this->superadmin())
            ->test(CompanyIndex::class)
            ->set('name', 'Otra empresa')
            ->set('admin_name', 'Otro')
            ->set('admin_email', 'ana@lopez.cr')
            ->set('admin_password', 'clave-inicial')
            ->set('branch_name', 'Sede')
            ->call('save')
            ->assertHasErrors(['admin_email']);
    }

    public function test_el_comando_de_consola_da_de_alta_una_empresa(): void
    {
        $this->artisan('empresa:crear', [
            '--nombre' => 'Transportes López',
            '--admin-nombre' => 'Ana López',
            '--admin-correo' => 'ana@lopez.cr',
            '--admin-clave' => 'clave-inicial',
            '--prefijo' => 'SJ',
        ])->assertSuccessful();

        $this->assertTrue(Company::where('name', 'Transportes López')->exists());
    }
}
