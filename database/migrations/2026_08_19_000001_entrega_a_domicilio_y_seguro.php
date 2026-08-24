<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega a domicilio, cargo por valor declarado y control de descuentos.
 *
 * Los tres son cobros o controles que hasta ahora vivían fuera del sistema: la
 * entrega a domicilio se acordaba de palabra, el seguro sobre el valor
 * declarado no se cobraba, y cualquier cajero podía descontar lo que quisiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Entrega en la puerta y no en la sucursal de destino.
            $table->boolean('home_delivery')->default(false)->after('delivery_branch_id');
            $table->string('delivery_address')->nullable()->after('home_delivery');
            $table->decimal('home_delivery_fee', 12, 2)->default(0)->after('delivery_address');

            // Cargo por asegurar la mercancía: un porcentaje del valor declarado.
            $table->decimal('insurance_fee', 12, 2)->default(0)->after('declared_value');

            // Quién autorizó el descuento. Sin esto, un descuento no tiene dueño.
            $table->foreignId('discount_authorized_by')->nullable()->after('discount_amount')
                ->constrained('users')->nullOnDelete();

            $table->index('home_delivery');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            // Porcentaje del valor declarado que se cobra como seguro.
            $table->decimal('insurance_percent', 5, 2)->default(7);
            // Clave que habilita a un cajero a descontar. Cifrada en el modelo.
            $table->text('discount_authorization_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_authorized_by');
            $table->dropIndex(['home_delivery']);
            $table->dropColumn(['home_delivery', 'delivery_address', 'home_delivery_fee', 'insurance_fee']);
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['insurance_percent', 'discount_authorization_code']);
        });
    }
};
