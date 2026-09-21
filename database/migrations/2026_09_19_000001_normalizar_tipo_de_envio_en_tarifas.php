<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repara las tarifas guardadas con el tipo de envío en cadena vacía.
 *
 * La pantalla de Tarifario guardaba '' en vez de NULL cuando se elegía «Todos
 * los tipos». El tarifario busca las comodines con `whereNull`, así que esas
 * tarifas no aplicaban a ninguna guía: aparecían en la lista con su precio, se
 * veían bien, y el cajero terminaba digitando el monto a mano sin saber por qué.
 *
 * El alta ya quedó corregida (RateIndex::save). Esto arregla las que se
 * cargaron antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rates')) {
            return;
        }

        DB::table('rates')->where('shipment_type', '')->update(['shipment_type' => null]);
    }

    public function down(): void
    {
        // No se revierte: volver a poner cadenas vacías sería restaurar el fallo.
    }
};
