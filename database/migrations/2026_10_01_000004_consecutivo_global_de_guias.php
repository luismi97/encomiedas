<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El consecutivo del código guía pasa a ser uno solo para todo el sistema.
     *
     * Era por empresa (guide_sequences.company_id): dos empresas con una sede
     * «SJ» emitían las dos SJ-LIM-00005, y el rastreo público tenía que
     * preguntar de cuál era. Ahora el contador es por par de prefijos y lo
     * comparten todas: si una emitió SJ-LIM-00005, la siguiente guía SJ-LIM de
     * cualquier empresa es la 00006. Cada empresa ve saltos en su numeración;
     * es el precio, aceptado, de que un código identifique una sola encomienda.
     *
     * Arranca donde iba la empresa más adelantada en cada ruta, para no volver
     * a emitir un número que ya está impreso en algún paquete.
     *
     * No se agrega un índice único global sobre invoices.code: los códigos
     * repetidos emitidos antes de esto siguen existiendo y lo impedirían. El
     * generador comprueba contra todas las empresas antes de usar un código.
     */
    public function up(): void
    {
        if (! Schema::hasTable('guide_code_sequences')) {
            Schema::create('guide_code_sequences', function (Blueprint $table) {
                $table->id();
                $table->string('origin_prefix', 10);
                $table->string('destination_prefix', 10);
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();

                $table->unique(['origin_prefix', 'destination_prefix'], 'guide_code_sequences_par_unique');
            });
        }

        $ahora = now();

        DB::table('guide_sequences')
            ->select('origin_prefix', 'destination_prefix', DB::raw('MAX(last_number) as ultimo'))
            ->groupBy('origin_prefix', 'destination_prefix')
            ->orderBy('origin_prefix')
            ->get()
            ->each(fn ($fila) => DB::table('guide_code_sequences')->updateOrInsert(
                ['origin_prefix' => $fila->origin_prefix, 'destination_prefix' => $fila->destination_prefix],
                ['last_number' => (int) $fila->ultimo, 'created_at' => $ahora, 'updated_at' => $ahora],
            ));
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_code_sequences');
    }
};
