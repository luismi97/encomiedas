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
 * `sistema:instalar` — de cero a poder entrar, en un comando.
 *
 * El camino de instalación estaba repartido en cuatro comandos y un seeder que
 * además crea datos de demostración. Encadenarlos a mano deja pasos afuera; el
 * clásico es olvidar el usuario y quedarse sin poder abrir el sistema.
 */
class ComandoInstalarTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_comando_existe_y_se_describe(): void
    {
        $this->artisan('sistema:instalar', ['--force' => true])->assertSuccessful();
    }

    public function test_deja_el_sistema_utilizable(): void
    {
        $this->artisan('sistema:instalar', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());
        $this->assertTrue(Tax::where('is_default', true)->exists());
        $this->assertNotNull(CompanySetting::first());
    }

    /** «Sin datos» es literal: nada de operación inventada. */
    public function test_no_deja_ningun_dato_de_operacion(): void
    {
        $this->artisan('sistema:instalar', ['--force' => true]);

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Branch::count());
    }

    public function test_la_contrasena_que_imprime_sirve_para_entrar(): void
    {
        putenv('ADMIN_PASSWORD=');

        $this->artisan('sistema:instalar', ['--force' => true])->assertSuccessful();

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $contrasena = ProduccionSeeder::$contrasenaGenerada;

        $this->assertNotNull($contrasena, 'Sin contraseña impresa nadie puede entrar.');
        $this->assertTrue(Hash::check($contrasena, $admin->password));

        // Y de verdad autentica, no solo coincide el hash.
        $this->post('/login', ['login' => $admin->email, 'password' => $contrasena])
            ->assertRedirect();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_muestra_las_credenciales_en_pantalla(): void
    {
        putenv('ADMIN_PASSWORD=');

        $this->artisan('sistema:instalar', ['--force' => true])
            ->expectsOutputToContain('Entrá con estas credenciales')
            ->expectsOutputToContain('no se vuelve a mostrar')
            ->assertSuccessful();
    }

    /** Reejecutarlo no puede dejar afuera a quien ya administra. */
    public function test_sobre_un_sistema_ya_instalado_no_pisa_al_admin(): void
    {
        $original = User::create([
            'name' => 'Dueño', 'username' => 'dueno', 'email' => 'dueno@t.test',
            'password' => bcrypt('la-mia'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        $this->artisan('sistema:instalar', ['--force' => true])
            ->expectsOutputToContain('Ya había un administrador activo')
            ->assertSuccessful();

        $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());
        $this->assertTrue(Hash::check('la-mia', $original->fresh()->password));
    }

    /** --fresh borra la operación entera: en producción tiene que preguntar. */
    public function test_en_produccion_pide_confirmacion(): void
    {
        app()['env'] = 'production';

        $this->artisan('sistema:instalar', ['--fresh' => true])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();

        app()['env'] = 'testing';
    }

    // ── Migraciones sobrantes de otra instalación ─────────────────────

    /** Deja un archivo que crea una tabla que otra migración ya crea. */
    private function migracionDuplicada(): string
    {
        $ruta = database_path('migrations/2014_10_12_000000_create_users_table.php');

        \Illuminate\Support\Facades\File::put($ruta, <<<'PHP'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;
        return new class extends Migration {
            public function up(): void {
                Schema::create('users', function (Blueprint $t) { $t->id(); });
            }
            public function down(): void { Schema::dropIfExists('users'); }
        };
        PHP);

        return $ruta;
    }

    /**
     * El comando entraba a migrar sabiendo que iba a chocar, y dejaba el
     * esquema a medias: unas tablas creadas y otras no.
     */
    public function test_se_detiene_antes_de_tocar_la_base_si_hay_duplicadas(): void
    {
        $ruta = $this->migracionDuplicada();

        try {
            $this->artisan('sistema:instalar', ['--fresh' => true, '--force' => true])
                ->expectsOutputToContain('la crean 2 migraciones')
                ->expectsOutputToContain('--limpiar')
                ->assertFailed();

            // Y no llegó a crear el administrador: no empezó nada.
            $this->assertSame(0, User::where('role', User::ROLE_ADMIN)->count());
        } finally {
            \Illuminate\Support\Facades\File::delete($ruta);
        }
    }

    public function test_nombra_cual_es_la_sobrante(): void
    {
        $ruta = $this->migracionDuplicada();

        try {
            $this->artisan('sistema:instalar', ['--force' => true])
                ->expectsOutputToContain('0001_01_01_000000_create_users_table')
                ->expectsOutputToContain('2014_10_12_000000_create_users_table')
                ->assertFailed();
        } finally {
            \Illuminate\Support\Facades\File::delete($ruta);
        }
    }

    /**
     * Sin --fresh a propósito: la limpieza ocurre antes de migrar, y en SQLite
     * `migrate:fresh` termina con un VACUUM que no corre dentro de la
     * transacción de RefreshDatabase. En MySQL no existe ese límite.
     */
    public function test_con_limpiar_borra_la_sobrante_y_termina(): void
    {
        $ruta = $this->migracionDuplicada();

        try {
            $this->artisan('sistema:instalar', ['--limpiar' => true, '--force' => true])
                ->assertSuccessful();

            $this->assertFileDoesNotExist($ruta, 'La sobrante tenía que eliminarse.');
            $this->assertFileExists(database_path('migrations/0001_01_01_000000_create_users_table.php'),
                'La del proyecto NO se toca.');
            $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());
        } finally {
            \Illuminate\Support\Facades\File::delete($ruta);
        }
    }

    /** Sin duplicados no molesta con nada. */
    public function test_una_instalacion_sana_no_reporta_duplicados(): void
    {
        $this->artisan('sistema:instalar', ['--force' => true])
            ->expectsOutputToContain('sin duplicados')
            ->assertSuccessful();
    }

    public function test_respeta_las_credenciales_del_env(): void
    {
        putenv('ADMIN_EMAIL=dueno@miempresa.cr');
        putenv('ADMIN_PASSWORD=mi-clave-elegida');

        $this->artisan('sistema:instalar', ['--force' => true])->assertSuccessful();

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->assertSame('dueno@miempresa.cr', $admin->email);
        $this->assertTrue(Hash::check('mi-clave-elegida', $admin->password));

        putenv('ADMIN_EMAIL='); putenv('ADMIN_PASSWORD=');
    }
}
