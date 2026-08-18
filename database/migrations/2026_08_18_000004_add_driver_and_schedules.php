<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            // El chofer era solo texto. Como usuario, puede entrar a ver su
            // propio cierre desde el celular sin ver los de nadie más.
            $table->foreignId('driver_user_id')->nullable()->after('driver_name')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('branches', function (Blueprint $table) {
            /*
             | Horario de atención por día, como
             |   {"1":{"abre":"08:00","cierra":"17:00"}, "0":null}
             | donde la llave es el día de la semana (0 = domingo) y null = cerrado.
             |
             | En JSON y no en tabla aparte porque se lee siempre completo, junto
             | a la sede, y nunca se consulta por horario suelto.
             */
            $table->json('business_hours')->nullable()->after('receipt_paper_width');
        });

        // Feriados: son del país, no de una sede.
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('business_hours');
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_user_id');
        });
    }
};
