<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Crea la base de datos declarada en el .env.
 *
 * Laravel no trae nada equivalente porque da por hecho que la base ya existe:
 * `migrate` crea las TABLAS dentro de una base, no la base. En hostings como
 * Cloudways la crea el panel al dar de alta la aplicación, pero en local, en un
 * servidor propio o al levantar un ambiente nuevo hace falta este paso.
 */
class CreateDatabase extends Command
{
    protected $signature = 'db:create
        {--connection= : Conexión a usar (por defecto la del .env)}
        {--drop : Borra la base antes de crearla. DESTRUCTIVO.}';

    protected $description = 'Crea la base de datos configurada, si no existe';

    public function handle(): int
    {
        $nombre = $this->option('connection') ?: config('database.default');
        $conexion = config("database.connections.{$nombre}");

        if (!$conexion) {
            $this->error("La conexión «{$nombre}» no existe en config/database.php.");

            return self::FAILURE;
        }

        if ($conexion['driver'] === 'sqlite') {
            return $this->crearSqlite($conexion);
        }

        if (!in_array($conexion['driver'], ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->error("El driver «{$conexion['driver']}» no está soportado por este comando.");

            return self::FAILURE;
        }

        $base = $conexion['database'];

        if (blank($base)) {
            $this->error('No hay nombre de base en la configuración: revisá DB_DATABASE en el .env.');

            return self::FAILURE;
        }

        // Conectarse SIN seleccionar la base: es la única forma de crearla, y
        // apuntar la conexión a una base inexistente falla antes de empezar.
        Config::set("database.connections.{$nombre}.database", null);
        DB::purge($nombre);

        try {
            if ($this->option('drop')) {
                if (!$this->confirmarBorrado($base)) {
                    return self::FAILURE;
                }

                DB::connection($nombre)->statement("DROP DATABASE IF EXISTS `{$base}`");
                $this->warn("Base «{$base}» eliminada.");
            }

            $existia = $this->existe($nombre, $conexion['driver'], $base);

            DB::connection($nombre)->statement($this->sentencia($conexion, $base));
        } catch (Throwable $e) {
            $this->error('No se pudo crear la base: ' . $e->getMessage());
            $this->newLine();
            $this->line('Suele ser que el usuario del .env no tiene permiso CREATE DATABASE.');
            $this->line('En hostings administrados (Cloudways, cPanel) la base se crea desde el panel.');

            return self::FAILURE;
        } finally {
            // Dejar la conexión como estaba, apuntando a su base.
            Config::set("database.connections.{$nombre}.database", $base);
            DB::purge($nombre);
        }

        $existia && !$this->option('drop')
            ? $this->info("La base «{$base}» ya existía: no se tocó.")
            : $this->info("Base «{$base}» creada ({$conexion['charset']} / {$conexion['collation']}).");

        $this->newLine();
        $this->line('Siguiente paso:  php artisan migrate --force');

        return self::SUCCESS;
    }

    private function sentencia(array $conexion, string $base): string
    {
        if ($conexion['driver'] === 'pgsql') {
            return "CREATE DATABASE \"{$base}\" ENCODING 'UTF8'";
        }

        // El charset y la collation salen de config/database.php para que la
        // base nueva coincida con lo que la aplicación espera escribir.
        return "CREATE DATABASE IF NOT EXISTS `{$base}` "
            . "CHARACTER SET {$conexion['charset']} COLLATE {$conexion['collation']}";
    }

    private function existe(string $conexionNombre, string $driver, string $base): bool
    {
        if ($driver === 'pgsql') {
            return (bool) DB::connection($conexionNombre)
                ->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$base]);
        }

        return (bool) DB::connection($conexionNombre)
            ->selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$base]);
    }

    private function crearSqlite(array $conexion): int
    {
        $ruta = $conexion['database'];

        if ($ruta === ':memory:') {
            $this->info('SQLite en memoria: no hay archivo que crear.');

            return self::SUCCESS;
        }

        if (file_exists($ruta)) {
            $this->info("El archivo «{$ruta}» ya existe: no se tocó.");

            return self::SUCCESS;
        }

        touch($ruta);
        $this->info("Archivo SQLite creado en «{$ruta}».");

        return self::SUCCESS;
    }

    private function confirmarBorrado(string $base): bool
    {
        if (app()->environment('production')) {
            $this->error('--drop no se permite en producción.');

            return false;
        }

        return $this->confirm("Esto BORRA la base «{$base}» y todos sus datos. ¿Continuar?", false);
    }
}
