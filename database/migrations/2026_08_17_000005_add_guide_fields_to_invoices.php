<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Remitente y destinatario dejan de ser solo texto libre. Se
            // conservan las columnas de texto: una encomienda de mostrador no
            // obliga a registrar al cliente, y el histórico ya está ahí.
            $table->foreignId('sender_customer_id')->nullable()->after('delivery_branch_id')
                ->constrained('customers')->nullOnDelete();
            $table->foreignId('recipient_customer_id')->nullable()->after('sender_customer_id')
                ->constrained('customers')->nullOnDelete();

            $table->string('shipment_type', 20)->nullable()->after('recipient_customer_id');
            // Valor declarado para efectos de seguro; no entra en el cobro.
            $table->decimal('declared_value', 12, 2)->default(0)->after('shipment_type');

            // Momento en que llegó a la sede destino: de aquí se cuentan los
            // días para próximo-a-desecho.
            $table->dateTime('arrived_at')->nullable()->after('delivered_at');
            $table->dateTime('disposal_warned_at')->nullable()->after('arrived_at');
            $table->dateTime('disposed_at')->nullable()->after('disposal_warned_at');

            // Quién retiró: exigido por el requisito de entrega con evidencia.
            $table->string('received_by_name')->nullable()->after('disposed_at');
            $table->string('received_by_identification', 20)->nullable()->after('received_by_name');

            $table->index('arrived_at');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            // Dimensiones en cm para el peso volumétrico.
            $table->decimal('length_cm', 8, 2)->nullable()->after('weight');
            $table->decimal('width_cm', 8, 2)->nullable()->after('length_cm');
            $table->decimal('height_cm', 8, 2)->nullable()->after('width_cm');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_customer_id');
            $table->dropConstrainedForeignId('recipient_customer_id');
            $table->dropIndex(['arrived_at']);
            $table->dropColumn([
                'shipment_type', 'declared_value', 'arrived_at', 'disposal_warned_at',
                'disposed_at', 'received_by_name', 'received_by_identification',
            ]);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['length_cm', 'width_cm', 'height_cm']);
        });
    }
};
