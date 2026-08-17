<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Prefijo del código guía: SJ-LIM-00005. Va aparte de sucursal_code
            // (001) porque ese es de Hacienda y este es de cara al cliente.
            $table->string('prefix', 4)->nullable()->after('name');
        });

        // Semilla razonable para las sedes que ya existen: las tres primeras
        // letras del nombre. El administrador las ajusta después.
        foreach (DB::table('branches')->get(['id', 'name']) as $branch) {
            DB::table('branches')->where('id', $branch->id)->update([
                'prefix' => $this->prefijoTentativo($branch->name, $branch->id),
            ]);
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->unique('prefix');
        });
    }

    /**
     * Tres letras del nombre, sin tildes ni espacios. Si choca con otra sede se
     * le pega el id: es preferible un prefijo feo a una migracion que revienta
     * por el indice unico.
     */
    private function prefijoTentativo(string $nombre, int $id): string
    {
        $limpio = Str::upper(preg_replace('/[^A-Za-z]/', '', Str::ascii($nombre)));
        $base = substr($limpio ?: 'SED', 0, 3);

        $tomado = DB::table('branches')->where('prefix', $base)->exists();

        return $tomado ? substr($base, 0, 2) . $id : $base;
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['prefix']);
            $table->dropColumn('prefix');
        });
    }
};
