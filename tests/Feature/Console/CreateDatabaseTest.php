<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CreateDatabaseTest extends TestCase
{
    public function test_sqlite_en_memoria_no_tiene_archivo_que_crear(): void
    {
        $this->artisan('db:create')
            ->expectsOutputToContain('no hay archivo que crear')
            ->assertSuccessful();
    }

    public function test_crea_el_archivo_de_una_base_sqlite(): void
    {
        $ruta = storage_path('app/prueba-db-create.sqlite');
        @unlink($ruta);

        Config::set('database.connections.sqlite_prueba', [
            'driver' => 'sqlite', 'database' => $ruta, 'prefix' => '',
        ]);

        $this->artisan('db:create', ['--connection' => 'sqlite_prueba'])
            ->expectsOutputToContain('creado')
            ->assertSuccessful();

        $this->assertFileExists($ruta);

        // Segunda corrida: no debe romper ni pisar el archivo.
        $this->artisan('db:create', ['--connection' => 'sqlite_prueba'])
            ->expectsOutputToContain('ya existe')
            ->assertSuccessful();

        @unlink($ruta);
    }

    public function test_una_conexion_inexistente_falla_con_mensaje_claro(): void
    {
        $this->artisan('db:create', ['--connection' => 'no-existe'])
            ->expectsOutputToContain('no existe en config/database.php')
            ->assertFailed();
    }

    public function test_un_driver_no_soportado_falla_sin_intentarlo(): void
    {
        Config::set('database.connections.raro', ['driver' => 'sqlsrv', 'database' => 'x']);

        $this->artisan('db:create', ['--connection' => 'raro'])
            ->expectsOutputToContain('no está soportado')
            ->assertFailed();
    }

    public function test_sin_nombre_de_base_avisa_del_env(): void
    {
        Config::set('database.connections.vacia', [
            'driver' => 'mysql', 'database' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ]);

        $this->artisan('db:create', ['--connection' => 'vacia'])
            ->expectsOutputToContain('DB_DATABASE')
            ->assertFailed();
    }

    /** --drop borra datos: en producción no se permite ni preguntando. */
    public function test_drop_no_se_permite_en_produccion(): void
    {
        app()->detectEnvironment(fn () => 'production');

        Config::set('database.connections.mysql_prueba', [
            'driver' => 'mysql', 'database' => 'loquesea',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ]);

        $this->artisan('db:create', ['--connection' => 'mysql_prueba', '--drop' => true])
            ->expectsOutputToContain('no se permite en producción')
            ->assertFailed();
    }
}
