<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rutas predefinidas: el par origen–destino con nombre propio.
 *
 * El par ya vivía disperso —en cada guía, en cada cierre, en cada tarifa— pero
 * no existía como cosa. En el mostrador eso significa elegir dos sucursales en
 * cada encomienda, y las mismas dos treinta veces al día; una mano cansada
 * elige mal y el paquete sale hacia otra provincia.
 *
 * La ruta es un atajo, no una obligación: los selects de origen y destino siguen
 * ahí para el envío suelto. Exigirla habría significado que un destino nuevo no
 * se puede facturar hasta que alguien pase por Configuración, y en un mostrador
 * eso es una encomienda que se pierde.
 *
 * `transit_days` es lo único que la ruta sabe y la guía no: cuánto tarda
 * normalmente. Sirve para prometerle una fecha al cliente y para que el aviso de
 * guías estancadas mida contra lo que esa ruta tarda de verdad, en vez de contra
 * un número igual para todas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            // Nombre propio: «Limón directo» dice más que «SJO → LIM» cuando
            // hay dos maneras de llegar al mismo lugar.
            $table->string('name', 80);

            $table->foreignId('origin_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->cascadeOnDelete();

            $table->unsignedSmallInteger('transit_days')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Una sola ruta por par dentro de la empresa: dos rutas iguales con
            // nombres distintos es la clase de ambigüedad que nadie resuelve
            // después, porque ninguna de las dos está mal.
            $table->unique(
                ['company_id', 'origin_branch_id', 'destination_branch_id'],
                'shipping_routes_empresa_par_unique'
            );
            $table->index(['company_id', 'is_active'], 'shipping_routes_empresa_activa_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Queda registrada la ruta con la que se creó la guía: de ahí sale
            // la fecha estimada de llegada, que no se puede recalcular después
            // si alguien edita los días de tránsito de la ruta.
            $table->foreignId('shipping_route_id')->nullable()->after('delivery_branch_id')
                ->constrained('shipping_routes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['shipping_route_id']);
            $table->dropColumn('shipping_route_id');
        });

        Schema::dropIfExists('shipping_routes');
    }
};
