<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de bulto: paquete, caja, sobre, herramienta.
 *
 * Reemplaza al «código de paquete», que el cajero tenía que inventar renglón
 * por renglón y que nada en el sistema usaba como llave: no se buscaba ni se
 * rastreaba por él. La identidad del bulto la da el código guía en la etiqueta;
 * lo que hacía falta acá era decir QUÉ es lo que se está recibiendo.
 */
return new class extends Migration
{
    /** [nombre, frágil] */
    private const TIPOS = [
        ['Paquete', false],
        ['Caja', false],
        ['Sobre', false],
        ['Bolsa', false],
        ['Documento', false],
        ['Herramienta', false],
        ['Electrodoméstico', true],
        ['Frágil', true],
    ];

    public function up(): void
    {
        Schema::create('package_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            // Marca la etiqueta del bulto: es lo que ve quien lo carga.
            $table->boolean('is_fragile')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $ahora = now();

        foreach (self::TIPOS as $orden => [$nombre, $fragil]) {
            DB::table('package_types')->insert([
                'name' => $nombre,
                'is_fragile' => $fragil,
                'sort_order' => $orden,
                'is_active' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('package_type_id')->nullable()->after('invoice_id')
                ->constrained('package_types')->nullOnDelete();
        });

        // Los renglones ya registrados conservan su código: no hay forma de
        // deducirles el tipo, y borrarlo perdería lo único que los identifica.
        DB::statement('UPDATE invoice_items SET package_code = NULL WHERE package_code = ""');

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('package_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_type_id');
        });

        Schema::dropIfExists('package_types');
    }
};
