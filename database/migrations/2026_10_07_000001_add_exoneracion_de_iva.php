<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes exonerados del IVA.
 *
 * El servicio de encomienda está gravado al 13 %: un cliente no lo paga solo
 * porque lo diga, sino porque tiene una exoneración autorizada (zona franca,
 * diplomático, ley especial…), normalmente registrada en EXONET. La Factura
 * Electrónica v4.4 la declara línea por línea en el nodo Impuesto/Exoneracion
 * con el número de la autorización, y Hacienda la contrasta con su registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('tax_exempt')->default(false)->after('credit_cutoff_day');
            // Catálogo TipoDocumentoEX1 de Hacienda (04, 08, 99…).
            $table->string('exemption_document_type', 2)->nullable()->after('tax_exempt');
            $table->string('exemption_document_type_other', 100)->nullable()->after('exemption_document_type');
            $table->string('exemption_number', 40)->nullable()->after('exemption_document_type_other');
            // Catálogo NombreInstitucion (01 Ministerio de Hacienda…).
            $table->string('exemption_institution', 2)->nullable()->after('exemption_number');
            $table->string('exemption_institution_other', 160)->nullable()->after('exemption_institution');
            $table->unsignedInteger('exemption_article')->nullable()->after('exemption_institution_other');
            $table->unsignedInteger('exemption_inciso')->nullable()->after('exemption_article');
            $table->date('exemption_issued_at')->nullable()->after('exemption_inciso');
            $table->date('exemption_expires_at')->nullable()->after('exemption_issued_at');
            // TarifaExonerada: cuántos puntos del IVA no paga (13 = todo).
            $table->decimal('exemption_rate', 4, 2)->nullable()->after('exemption_expires_at');
            // CABYS que cubre la autorización, según EXONET. Vacío = no se sabe.
            $table->json('exemption_cabys')->nullable()->after('exemption_rate');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('tax_exempt')->default(false)->after('tax_total');
            // La exoneración con que se facturó, copiada del cliente: si mañana
            // vence o la cambian, esta guía tiene que seguir diciendo lo que dijo.
            $table->json('exemption')->nullable()->after('tax_exempt');
            // IVA que no se cobró. tax_total ya viene neto de esto.
            $table->decimal('exempt_tax_amount', 15, 5)->default(0)->after('exemption');
        });

        Schema::table('electronic_invoices', function (Blueprint $table) {
            // El contador declara las ventas exoneradas aparte de las gravadas.
            $table->decimal('total_exonerated', 18, 5)->default(0)->after('total_tax');
        });
    }

    public function down(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropColumn('total_exonerated');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['tax_exempt', 'exemption', 'exempt_tax_amount']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'tax_exempt', 'exemption_document_type', 'exemption_document_type_other',
                'exemption_number', 'exemption_institution', 'exemption_institution_other',
                'exemption_article', 'exemption_inciso', 'exemption_issued_at',
                'exemption_expires_at', 'exemption_rate', 'exemption_cabys',
            ]);
        });
    }
};
