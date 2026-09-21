<?php

namespace Tests\Feature\Ui;

use App\Livewire\Settings\CompanySettingsForm;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El logo de la empresa en el menú.
 *
 * Lo que más importa acá no es que se vea, sino lo que NO se acepta: un SVG es
 * un documento XML que admite <script> y se serviría desde este mismo dominio,
 * así que subir el logo sería una vía para ejecutar código en la sesión de todos
 * los cajeros.
 */
class LogoDeLaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Branch::firstOrCreate(['prefix' => 'SJ'], ['name'=>'San José','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);

        // La configuración exige estos datos para poder guardar.
        CompanySetting::instance()->forceFill([
            'name' => 'Transportes Solano S.A.',
            'identification_type' => '02', 'identification_number' => '3101234567',
            'activity_code' => '492300', 'province' => '1', 'canton' => '01', 'district' => '01',
        ])->save();
    }

    private function formulario()
    {
        return Livewire::actingAs($this->admin)->test(CompanySettingsForm::class);
    }

    private function imagen(string $nombre = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($nombre, 300, 300);
    }

    // ── Subida ────────────────────────────────────────────────────────

    public function test_se_sube_un_logo_y_queda_guardado(): void
    {
        $this->formulario()
            ->set('logo', $this->imagen())
            ->call('save')
            ->assertHasNoErrors();

        $ruta = CompanySetting::instance()->fresh()->logo_path;

        $this->assertNotNull($ruta);
        Storage::disk('public')->assertExists($ruta);
    }

    /**
     * Un SVG lleva código adentro y se serviría desde el dominio del sistema.
     * La regla `image` de Laravel lo acepta, por eso los tipos van listados.
     */
    public function test_un_svg_se_rechaza(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->formulario()
            ->set('logo', $svg)
            ->call('save')
            ->assertHasErrors('logo');

        $this->assertNull(CompanySetting::instance()->fresh()->logo_path);
    }

    public function test_un_archivo_que_no_es_imagen_se_rechaza(): void
    {
        $this->formulario()
            ->set('logo', UploadedFile::fake()->create('cartera.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasErrors('logo');
    }

    public function test_una_imagen_demasiado_pesada_se_rechaza(): void
    {
        $this->formulario()
            ->set('logo', UploadedFile::fake()->image('enorme.png', 400, 400)->size(2048))
            ->call('save')
            ->assertHasErrors('logo');
    }

    /** Cada cambio de formato dejaría un archivo huérfano si no se borra. */
    public function test_reemplazar_el_logo_borra_el_anterior(): void
    {
        $this->formulario()->set('logo', $this->imagen('primero.png'))->call('save');
        $primera = CompanySetting::instance()->fresh()->logo_path;

        $this->formulario()->set('logo', $this->imagen('segundo.jpg'))->call('save');
        $segunda = CompanySetting::instance()->fresh()->logo_path;

        $this->assertNotSame($primera, $segunda);
        Storage::disk('public')->assertMissing($primera);
        Storage::disk('public')->assertExists($segunda);
    }

    public function test_se_puede_quitar_y_vuelve_el_icono_del_sistema(): void
    {
        $this->formulario()->set('logo', $this->imagen())->call('save');
        $ruta = CompanySetting::instance()->fresh()->logo_path;

        $this->formulario()->call('quitarLogo');

        $this->assertNull(CompanySetting::instance()->fresh()->logo_path);
        Storage::disk('public')->assertMissing($ruta);
    }

    // ── En el menú ────────────────────────────────────────────────────

    public function test_el_logo_sale_en_el_menu(): void
    {
        $this->formulario()->set('logo', $this->imagen())->call('save');

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertSee('data-test="logo-empresa"', false);
    }

    public function test_sin_logo_el_menu_muestra_el_icono_generico(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertDontSee('data-test="logo-empresa"', false);
    }

    /**
     * Sin la marca de tiempo, cambiar el logo no se ve hasta que cada usuario
     * vacíe su caché, y el administrador concluye que la subida falló.
     */
    public function test_la_direccion_del_logo_cambia_al_reemplazarlo(): void
    {
        $this->formulario()->set('logo', $this->imagen())->call('save');

        $this->assertMatchesRegularExpression('/\?v=\d+$/', CompanySetting::instance()->fresh()->logoUrl());
    }

    /** Si el archivo desapareció del disco, el menú no puede mostrar un roto. */
    public function test_si_el_archivo_no_esta_no_se_muestra_nada(): void
    {
        $this->formulario()->set('logo', $this->imagen())->call('save');

        Storage::disk('public')->delete(CompanySetting::instance()->fresh()->logo_path);

        $this->assertNull(CompanySetting::instance()->fresh()->logoUrl());
    }

    /**
     * El superadministrador no está dentro de ninguna empresa: pedir el logo no
     * puede lanzar, que es lo que hace instance() con varias empresas.
     */
    public function test_el_superadministrador_no_revienta_con_varias_empresas(): void
    {
        $otra = Company::create(['name' => 'Empresa dos', 'slug' => 'empresa-dos', 'is_active' => true]);
        CompanySetting::withoutGlobalScopes()->create([
            'company_id' => $otra->id, 'environment' => 'sandbox', 'name' => 'Empresa dos',
        ]);

        $super = User::create(['name'=>'Súper','username'=>'super','email'=>'s@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_SUPERADMIN,'is_active'=>true]);
        $super->forceFill(['company_id' => null])->save();

        $this->actingAs($super)
            ->get(route('superadmin.companies.index'))
            ->assertOk();
    }
}
