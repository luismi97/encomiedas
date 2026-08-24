<?php

namespace Tests\Feature\Setup;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use Database\Seeders\ProduccionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Arranque de una instalación en producción.
 *
 * DatabaseSeeder encadena DemoDataSeeder, que crea sucursales inventadas y
 * guías ficticias —y esas guías consumen consecutivos reales de Hacienda—. Este
 * seeder deja solo lo que no se puede configurar sin haber entrado antes.
 */
class SeederProductivoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'un-token-de-al-menos-treinta-y-dos-caracteres';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::store('file')->flush();
        config(['app.deploy_token' => self::TOKEN]);
    }

    private function url(array $params = []): string
    {
        return '/__deploy/setup?' . http_build_query(['token' => self::TOKEN] + $params);
    }

    // ── El seeder ─────────────────────────────────────────────────────

    public function test_deja_configuracion_iva_y_un_administrador(): void
    {
        $this->seed(ProduccionSeeder::class);

        $this->assertNotNull(CompanySetting::first());
        $this->assertTrue(Tax::where('is_default', true)->exists());
        $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());
    }

    /** Lo que NO debe hacer: inventar datos de operación. */
    public function test_no_crea_datos_de_demostracion(): void
    {
        $this->seed(ProduccionSeeder::class);

        $this->assertSame(0, Invoice::count(), 'Una guía ficticia consume consecutivo de Hacienda.');
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Branch::count(),
            'Las sucursales llevan códigos de Hacienda propios: se cargan desde la pantalla.');
    }

    public function test_el_administrador_puede_entrar(): void
    {
        putenv('ADMIN_PASSWORD=');
        $this->seed(ProduccionSeeder::class);

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $contrasena = ProduccionSeeder::$contrasenaGenerada;

        $this->assertNotNull($contrasena);
        $this->assertTrue(Hash::check($contrasena, $admin->password));
        $this->assertTrue($admin->is_active);
    }

    /** Una contraseña fija en un sistema público dura lo que tarden en probarla. */
    public function test_la_contrasena_generada_no_es_predecible(): void
    {
        $this->seed(ProduccionSeeder::class);
        $primera = ProduccionSeeder::$contrasenaGenerada;

        User::query()->delete();

        $this->seed(ProduccionSeeder::class);
        $segunda = ProduccionSeeder::$contrasenaGenerada;

        $this->assertGreaterThanOrEqual(16, strlen($primera));
        $this->assertNotSame($primera, $segunda);
        $this->assertNotContains($primera, ['password', 'admin', 'secret', '12345678']);
    }

    public function test_respeta_las_credenciales_del_env(): void
    {
        putenv('ADMIN_EMAIL=dueno@miempresa.cr');
        putenv('ADMIN_PASSWORD=mi-contrasena-elegida');
        putenv('ADMIN_NAME=Kevin Ubau');

        $this->seed(ProduccionSeeder::class);

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->assertSame('dueno@miempresa.cr', $admin->email);
        $this->assertSame('Kevin Ubau', $admin->name);
        $this->assertTrue(Hash::check('mi-contrasena-elegida', $admin->password));
        $this->assertNull(ProduccionSeeder::$contrasenaGenerada, 'No se genera si viene del .env.');

        putenv('ADMIN_EMAIL='); putenv('ADMIN_PASSWORD='); putenv('ADMIN_NAME=');
    }

    /** Reejecutarlo no puede dejar afuera al dueño del sistema. */
    public function test_no_pisa_al_administrador_existente(): void
    {
        $original = User::create([
            'name' => 'Dueño', 'username' => 'dueno', 'email' => 'dueno@t.test',
            'password' => bcrypt('la-mia'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        $this->seed(ProduccionSeeder::class);

        $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());
        $this->assertTrue(Hash::check('la-mia', $original->fresh()->password));
        $this->assertNull(ProduccionSeeder::$correoCreado);
    }

    public function test_reejecutarlo_no_duplica_nada(): void
    {
        $this->seed(ProduccionSeeder::class);
        $this->seed(ProduccionSeeder::class);

        $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());
        $this->assertSame(1, Tax::where('name', 'IVA general')->count());
        $this->assertSame(1, CompanySetting::count());
    }

    /** Si el usuario derivado del correo está tomado, se numera. */
    public function test_el_nombre_de_usuario_no_choca(): void
    {
        User::create([
            'name' => 'Otro', 'username' => 'admin', 'email' => 'otro@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
        ]);

        $this->seed(ProduccionSeeder::class);

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->assertNotSame('admin', $admin->username);
    }

    // ── El endpoint ───────────────────────────────────────────────────

    public function test_sin_token_no_existe(): void
    {
        $this->get('/__deploy/setup?confirm=1')->assertNotFound();
    }

    public function test_pide_confirmacion(): void
    {
        $this->getJson($this->url())->assertStatus(428);
        $this->assertSame(0, User::count());
    }

    public function test_devuelve_la_contrasena_una_vez(): void
    {
        putenv('ADMIN_PASSWORD=');

        $r = $this->getJson($this->url(['confirm' => 1]))->assertOk();

        $r->assertJsonPath('listo', true);
        $this->assertNotEmpty($r->json('contrasena_generada'));
        $this->assertStringContainsString('ANOTÁ', $r->json('aviso'));

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->assertTrue(Hash::check($r->json('contrasena_generada'), $admin->password));
    }

    public function test_con_un_admin_existente_no_devuelve_contrasena(): void
    {
        User::create([
            'name' => 'Dueño', 'username' => 'dueno', 'email' => 'dueno@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        $r = $this->getJson($this->url(['confirm' => 1]))->assertOk();

        $this->assertNull($r->json('contrasena_generada'));
        $this->assertStringContainsString('Ya había un administrador', $r->json('aviso'));
    }
}
