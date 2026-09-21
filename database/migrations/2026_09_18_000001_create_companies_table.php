<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Convierte la instalación en multiempresa.
 *
 * Hasta acá cada cliente necesitaba su propia copia del sistema: su base, su
 * despliegue y su actualización aparte. Con una columna `company_id` en cada
 * tabla de negocio y un ámbito global que la aplica, una sola instalación
 * atiende a todas las empresas y ninguna ve lo de la otra.
 *
 * Lo que ya existe se conserva: todo pasa a ser de la empresa 1, armada con el
 * nombre que traiga la configuración actual. La operación en curso no se entera.
 *
 * Las columnas quedan NULAS a propósito y no NOT NULL:
 *  - el superadministrador es un usuario sin empresa, y así se reconoce;
 *  - cambiar a NOT NULL obliga a reconstruir la tabla en SQLite, y esta misma
 *    migración corre en las pruebas contra SQLite en memoria.
 * Quien exige el valor es la aplicación (BelongsToCompany lo rellena solo).
 */
return new class extends Migration
{
    /**
     * Tablas de negocio que pasan a ser de una empresa.
     *
     * No están las hijas puras —renglones de guía, impuestos de la guía,
     * conteos de arqueo, guías de un cierre—: nunca se consultan sueltas, solo
     * a través de su padre, que sí lleva la columna. Agregárselas sería una
     * columna más que mantener llena sin aislar nada nuevo.
     */
    private const TABLAS = [
        'users',
        'branches',
        'company_settings',
        'taxes',
        'customers',
        'rates',
        'package_types',
        'holidays',
        'denominations',
        'cash_registers',
        'cash_sessions',
        'invoices',
        'quotes',
        'dispatches',
        'credit_statements',
        'activity_logs',
        'electronic_billing_sequences',
        'electronic_invoices',
        'guide_sequences',
        'guide_incidents',
        'print_logs',
    ];

    /**
     * Índices únicos que dejan de ser globales.
     *
     * Dos empresas usan «SJ» como prefijo de su sede central y las dos emiten
     * su guía número 1: con el único global, la segunda en insertar reventaba.
     *
     * @var array<string,array<int,array<int,string>>>
     */
    private const UNICOS = [
        'branches'          => [['sucursal_code', 'terminal_code'], ['prefix']],
        'invoices'          => [['code']],
        'dispatches'        => [['code']],
        'quotes'            => [['code']],
        'credit_statements' => [['code']],
        'customers'         => [['identification']],
        'package_types'     => [['name']],
        'denominations'     => [['value']],
        'holidays'          => [['date']],
        'guide_sequences'   => [['origin_prefix', 'destination_prefix']],
    ];

    public function up(): void
    {
        // Idempotente de punta a punta: son veinte ALTER TABLE seguidos y MySQL
        // no los envuelve en una transacción. Si el proceso muere en el número
        // doce —se acabó el tiempo de la petición, se cayó la conexión—, volver
        // a correr la migración tiene que retomar donde quedó, no reventar
        // sobre lo que ya hizo.
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                // Identifica a la empresa en el rastreo público, donde no hay
                // sesión de la cual deducirla:
                //   /rastreo/transportes-lopez/SJ-LIM-00005
                $table->string('slug', 60)->unique();
                $table->string('legal_name')->nullable();
                $table->string('identification', 20)->nullable();
                $table->string('email')->nullable();
                $table->string('phone', 30)->nullable();
                // Suspender en vez de borrar: un cliente que deja de pagar
                // vuelve, y su historial fiscal hay que conservarlo aunque no
                // pueda entrar.
                $table->boolean('is_active')->default(true);
                $table->date('expires_on')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        $empresaId = $this->crearEmpresaInicial();

        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla) || Schema::hasColumn($tabla, 'company_id')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('id');
                $table->index('company_id');
            });

            // Antes de la llave foránea: con filas en null, MySQL la acepta, pero
            // dejarlas llenas evita que un backfill posterior se olvide de alguna.
            DB::table($tabla)->update(['company_id' => $empresaId]);

            $this->agregarLlaveForanea($tabla);
        }

        // El superadministrador no es de ninguna empresa: esa es justamente su
        // marca. Se le quita la que el backfill le puso a todos por igual.
        if (Schema::hasTable('users')) {
            DB::table('users')->where('role', 'superadmin')->update(['company_id' => null]);
        }

        $this->reescribirUnicos();
    }

    public function down(): void
    {
        foreach (self::UNICOS as $tabla => $indices) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($indices as $columnas) {
                $nuevo = $this->nombreDelUnico($tabla, array_merge(['company_id'], $columnas));

                Schema::table($tabla, function (Blueprint $table) use ($tabla, $columnas, $nuevo) {
                    if ($this->existeIndice($tabla, $nuevo)) {
                        $table->dropUnique($nuevo);
                    }

                    if (! $this->existeIndice($tabla, $this->nombreDelUnico($tabla, $columnas))) {
                        $table->unique($columnas);
                    }
                });
            }
        }

        foreach (array_reverse(self::TABLAS) as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'company_id')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                if ($this->soportaLlavesForaneas()) {
                    $table->dropForeign($tabla . '_company_id_foreign');
                }

                $table->dropColumn('company_id');
            });
        }

        Schema::dropIfExists('companies');
    }

    /**
     * La empresa que hereda todo lo que ya estaba.
     *
     * El nombre sale de la configuración fiscal actual, que es el que el cliente
     * ya ve impreso en sus facturas; solo si está vacía se cae a uno genérico.
     */
    private function crearEmpresaInicial(): int
    {
        // Ya la creó una corrida anterior que no llegó al final: se reusa en vez
        // de dejar dos empresas iniciales compitiendo por los mismos datos.
        if ($existente = DB::table('companies')->orderBy('id')->first()) {
            return (int) $existente->id;
        }

        $configuracion = Schema::hasTable('company_settings')
            ? DB::table('company_settings')->orderBy('id')->first()
            : null;

        $nombre = trim((string) ($configuracion->name ?? '')) ?: 'Empresa principal';

        return (int) DB::table('companies')->insertGetId([
            'name'           => $nombre,
            'slug'           => Str::slug($nombre) ?: 'empresa-principal',
            'legal_name'     => $configuracion->name ?? null,
            'identification' => $configuracion->identification_number ?? null,
            'email'          => $configuracion->email ?? null,
            'phone'          => $configuracion->phone ?? null,
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /**
     * Cambia cada único global por uno por empresa.
     *
     * Se hace al final, cuando company_id ya está lleno: crear el único nuevo
     * con la columna en null colapsaría todas las filas contra el mismo valor.
     */
    private function reescribirUnicos(): void
    {
        foreach (self::UNICOS as $tabla => $indices) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($indices as $columnas) {
                $viejo = $this->nombreDelUnico($tabla, $columnas);
                $nuevo = $this->nombreDelUnico($tabla, array_merge(['company_id'], $columnas));

                Schema::table($tabla, function (Blueprint $table) use ($tabla, $columnas, $viejo, $nuevo) {
                    if ($this->existeIndice($tabla, $viejo)) {
                        $table->dropUnique($viejo);
                    }

                    if (! $this->existeIndice($tabla, $nuevo)) {
                        // Con nombre explícito: el que arma Laravel juntando las
                        // tres columnas pasa de los 64 caracteres que acepta
                        // MySQL y la migración se cae a mitad de camino.
                        $table->unique(array_merge(['company_id'], $columnas), $nuevo);
                    }
                });
            }
        }
    }

    /**
     * SQLite no sabe agregar una llave foránea a una tabla ya creada, y las
     * pruebas corren ahí. En MySQL sí se agrega: es la red que atrapa una
     * empresa mal armada antes de que deje filas huérfanas.
     */
    private function agregarLlaveForanea(string $tabla): void
    {
        if (! $this->soportaLlavesForaneas()) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    private function soportaLlavesForaneas(): bool
    {
        return DB::connection()->getDriverName() !== 'sqlite';
    }

    /**
     * El nombre del índice, corto a la fuerza.
     *
     * MySQL corta en 64 caracteres, y «guide_sequences_company_id_origin_prefix
     * _destination_prefix_unique» se pasa. Cuando el natural no cabe se usa una
     * huella del mismo nombre: sigue siendo determinista, que es lo que permite
     * volver a encontrarlo para borrarlo.
     */
    private function nombreDelUnico(string $tabla, array $columnas): string
    {
        $natural = $tabla . '_' . implode('_', $columnas) . '_unique';

        return strlen($natural) <= 64
            ? $natural
            : $tabla . '_' . substr(md5($natural), 0, 12) . '_unique';
    }

    private function existeIndice(string $tabla, string $nombre): bool
    {
        foreach (Schema::getIndexes($tabla) as $indice) {
            if (($indice['name'] ?? null) === $nombre) {
                return true;
            }
        }

        return false;
    }
};
