<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Anulación: hoy una guía se anula y no queda constancia de por qué
            // ni de quién lo autorizó, que es justo lo que se audita después.
            $table->string('cancellation_reason')->nullable()->after('disposed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable()->after('cancelled_by');

            // Evidencia de entrega. La firma va en la fila y no en disco: es un
            // PNG de pocos KB, se consulta siempre junto a la guía y así no hay
            // archivos huérfanos si alguien borra el storage.
            $table->longText('delivery_signature')->nullable()->after('received_by_identification');
            $table->string('delivery_photo_path')->nullable()->after('delivery_signature');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'cancellation_reason', 'cancelled_at',
                'delivery_signature', 'delivery_photo_path',
            ]);
        });
    }
};
