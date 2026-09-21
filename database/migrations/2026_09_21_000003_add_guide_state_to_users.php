<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué guías de pantalla ya vio cada usuario.
 *
 * Va por usuario y no por navegador: el mostrador se atiende desde varias
 * computadoras y la misma persona no tiene por qué volver a ver el recorrido
 * por haberse sentado en otra silla. Al revés también importa: el cajero nuevo
 * que usa la máquina de siempre sí tiene que verlo.
 *
 * Una columna JSON y no una tabla aparte porque es un dato de preferencia, no
 * de operación: nunca se consulta al revés —«quiénes vieron la guía X»— y no
 * vale una tabla con sus índices y su migración de borrado en cascada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('guide_state')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('guide_state');
        });
    }
};
