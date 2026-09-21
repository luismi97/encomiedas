<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para el listado y el buscador con la tabla ya grande.
 *
 * Dos problemas distintos, los dos del mismo origen: tres mil guías diarias son
 * más de un millón de filas al año.
 *
 * 1) Los índices por volumen se crearon antes de que el sistema fuera
 *    multiempresa, así que ninguno empieza por `company_id`. Toda consulta filtra
 *    por empresa —lo hace el ámbito global, sin que la pantalla lo pida—, y un
 *    índice que no lleva esa columna adelante obliga a leer filas de más para
 *    después descartarlas. Los nuevos la ponen primero.
 *
 * 2) Buscar un nombre con `LIKE '%juan%'` no puede usar índice alguno. El índice
 *    FULLTEXT sí, y es para lo que está. Solo existe en MySQL: en SQLite, donde
 *    corren las pruebas, la búsqueda cae al LIKE de siempre y con esas decenas de
 *    filas da exactamente igual.
 *
 * No se borran los índices viejos: siguen sirviendo a consultas que no filtran
 * por empresa —las del superadministrador— y el costo de mantenerlos con tres
 * mil escrituras al día es despreciable frente al riesgo de quitarlos.
 */
return new class extends Migration
{
    private function esMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    public function up(): void
    {
        $mysql = $this->esMysql();

        if (Schema::hasColumn('invoices', 'company_id')) {
            Schema::table('invoices', function (Blueprint $table) use ($mysql) {
                // El listado: empresa + rango de fechas, ordenado por fecha.
                $table->index(['company_id', 'created_at'], 'invoices_empresa_fecha_idx');
                // El mismo listado filtrado por estado, que es el filtro más usado.
                $table->index(['company_id', 'status', 'created_at'], 'invoices_empresa_estado_fecha_idx');
                // El aviso de estancadas y el ciclo de desecho.
                $table->index(['company_id', 'status', 'arrived_at'], 'invoices_empresa_estado_llegada_idx');

                if ($mysql) {
                    $table->fullText(['sender_name', 'recipient_name'], 'invoices_nombres_fulltext');
                }
            });
        }

        if (Schema::hasColumn('customers', 'company_id')) {
            Schema::table('customers', function (Blueprint $table) use ($mysql) {
                $table->index(['company_id', 'is_active', 'name'], 'customers_empresa_activo_nombre_idx');

                if ($mysql) {
                    $table->fullText(['name', 'commercial_name'], 'customers_nombres_fulltext');
                }
            });
        }
    }

    public function down(): void
    {
        $mysql = $this->esMysql();

        if (Schema::hasColumn('invoices', 'company_id')) {
            Schema::table('invoices', function (Blueprint $table) use ($mysql) {
                $table->dropIndex('invoices_empresa_fecha_idx');
                $table->dropIndex('invoices_empresa_estado_fecha_idx');
                $table->dropIndex('invoices_empresa_estado_llegada_idx');

                if ($mysql) {
                    $table->dropFullText('invoices_nombres_fulltext');
                }
            });
        }

        if (Schema::hasColumn('customers', 'company_id')) {
            Schema::table('customers', function (Blueprint $table) use ($mysql) {
                $table->dropIndex('customers_empresa_activo_nombre_idx');

                if ($mysql) {
                    $table->dropFullText('customers_nombres_fulltext');
                }
            });
        }
    }
};
