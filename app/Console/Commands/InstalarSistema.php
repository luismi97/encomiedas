<?php

namespace App\Console\Commands;

use Database\Seeders\ProduccionSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deja el sistema listo desde cero: base, esquema y un administrador.
 *
 * Existe porque el camino de instalación estaba repartido en cuatro comandos y
 * un seeder que además crea datos de demostración. Encadenarlos a mano deja
 * afuera pasos —el clásico es olvidar el usuario y quedarse sin poder entrar—.
 *
 *   php artisan sistema:instalar            instala sobre lo que haya
 *   php artisan sistema:instalar --fresh    BORRA TODO y reconstruye
 */
class InstalarSistema extends Command
{
    use ConfirmableTrait;

    protected $signature = 'sistema:instalar
        {--fresh : Borra todas las tablas antes de crearlas. DESTRUCTIVO.}
        {--limpiar : Elimina las migraciones duplicadas que sobraron de otra instalación.}
        {--force : Ejecuta sin preguntar, incluso en producción.}';

    protected $description = 'Instala el sistema desde cero: base de datos, esquema y usuario administrador';

    public function handle(): int
    {
        // En producción pregunta antes: --fresh borra la operación entera.
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->components->info('Instalando Encomiendas CR');

        // Antes de tocar la base: un archivo duplicado revienta a mitad de la
        // migración y deja el esquema por la mitad, que es peor que no empezar.
        if (! $this->revisarMigracionesDuplicadas()) {
            return self::FAILURE;
        }

        if (! $this->prepararBase()) {
            return self::FAILURE;
        }

        if (! $this->construirEsquema()) {
            return self::FAILURE;
        }

        $this->call('db:seed', [
            '--class' => ProduccionSeeder::class,
            '--force' => true,
        ]);

        $this->mostrarCredenciales();

        return self::SUCCESS;
    }

    /**
     * Detecta migraciones que crean una tabla que otra ya crea.
     *
     * Es la firma de un archivo sobrante de una instalación anterior: Laravel 11
     * consolidó las migraciones por defecto en 0001_01_01_*, y las de Laravel 10
     * que quedaron en disco intentan crear las mismas tablas. Migrar con las dos
     * presentes falla siempre, y el orden alfabético garantiza que la vieja corra
     * después de la nueva.
     */
    private function revisarMigracionesDuplicadas(): bool
    {
        $porTabla = [];

        foreach (glob(database_path('migrations/*.php')) as $ruta) {
            foreach ($this->tablasQueCrea($ruta) as $tabla) {
                $porTabla[$tabla][] = basename($ruta, '.php');
            }
        }

        $duplicadas = array_filter($porTabla, fn ($archivos) => count($archivos) > 1);

        if ($duplicadas === []) {
            $this->components->twoColumnDetail('Migraciones', '<fg=green>sin duplicados</>');

            return true;
        }

        // La que sobra es la posterior: la anterior es la que el proyecto usa.
        $sobrantes = [];

        foreach ($duplicadas as $tabla => $archivos) {
            sort($archivos);
            $this->components->error("La tabla «{$tabla}» la crean " . count($archivos) . ' migraciones:');

            foreach ($archivos as $i => $archivo) {
                $this->components->twoColumnDetail(
                    '  ' . $archivo,
                    $i === 0 ? '<fg=green>la del proyecto</>' : '<fg=red>sobrante</>'
                );

                if ($i > 0) {
                    $sobrantes[] = $archivo;
                }
            }
        }

        $sobrantes = array_values(array_unique($sobrantes));

        if (! $this->option('limpiar')) {
            $this->newLine();
            $this->components->warn('Migrar así falla a mitad de camino y deja el esquema incompleto.');
            $this->components->info('Volvé a correrlo con --limpiar para eliminar los archivos sobrantes.');

            return false;
        }

        foreach ($sobrantes as $archivo) {
            $ruta = database_path("migrations/{$archivo}.php");

            $this->components->task("Eliminando {$archivo}", fn () => @unlink($ruta));
        }

        return true;
    }

    /**
     * Qué tablas crea una migración, leyendo el archivo.
     *
     * Se analiza el texto en vez de ejecutarla: ejecutarla para averiguarlo es
     * justo lo que revienta.
     *
     * @return array<int,string>
     */
    private function tablasQueCrea(string $archivo): array
    {
        $codigo = @file_get_contents($archivo);

        if ($codigo === false) {
            return [];
        }

        preg_match_all(
            '/Schema::(?:connection\([^)]*\)->)?create\(\s*[\'"]([^\'"]+)[\'"]/',
            $codigo,
            $coincidencias
        );

        return array_values(array_unique($coincidencias[1] ?? []));
    }

    /** La base tiene que existir antes de migrar. */
    private function prepararBase(): bool
    {
        try {
            DB::connection()->getPdo();
            $this->components->twoColumnDetail('Base de datos', '<fg=green>ya existe</>');

            return true;
        } catch (Throwable $e) {
            $this->components->task('Creando la base de datos', fn () => $this->call('db:create') === self::SUCCESS);
        }

        try {
            DB::purge();
            DB::connection()->getPdo();

            return true;
        } catch (Throwable $e) {
            $this->components->error('No se pudo conectar a la base: ' . $e->getMessage());
            $this->components->warn('En hostings administrados la base se crea desde el panel, '
                . 'y el usuario del .env no suele tener permiso para crearla.');

            return false;
        }
    }

    private function construirEsquema(): bool
    {
        $comando = $this->option('fresh') ? 'migrate:fresh' : 'migrate';

        if ($this->option('fresh')) {
            $this->components->warn('--fresh: se borran todas las tablas y se pierden los datos.');
        }

        return $this->call($comando, ['--force' => true]) === self::SUCCESS;
    }

    /**
     * La contraseña se muestra una sola vez.
     *
     * No se guarda en ningún lado: si se pierde, se restablece por correo o se
     * vuelve a correr el comando con la base vacía.
     */
    private function mostrarCredenciales(): void
    {
        $this->newLine();

        if (! ProduccionSeeder::$correoCreado) {
            $this->components->info('Ya había un administrador activo: no se creó ninguno.');
            $this->components->warn('Si perdiste el acceso, usá «olvidé mi contraseña» en el login.');

            return;
        }

        $this->components->info('Listo. Entrá con estas credenciales:');
        $this->components->twoColumnDetail('Usuario', ProduccionSeeder::$correoCreado);

        if ($contrasena = ProduccionSeeder::$contrasenaGenerada) {
            $this->components->twoColumnDetail('Contraseña', "<fg=yellow>{$contrasena}</>");
            $this->newLine();
            $this->components->warn('ANOTALA: no se vuelve a mostrar. Cambiala al entrar.');
        } else {
            $this->components->twoColumnDetail('Contraseña', 'la de ADMIN_PASSWORD en el .env');
        }

        $this->newLine();
        $this->components->info('Siguiente paso: cargar las sucursales con sus códigos de Hacienda.');
    }
}
