<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bitácora inmutable de estados: se escribe una fila por cambio y nunca
        // se edita ni se borra. Es lo que sostiene el requisito de trazabilidad
        // y lo que alimenta la línea de tiempo del portal público.
        Schema::create('guide_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable(); // null = creación
            $table->string('to_status', 20);
            // Sede donde ocurrió: no siempre es la de origen ni la de destino
            // (puede haber transbordo).
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // manual | scan | system — de dónde vino el cambio.
            $table->string('source', 10)->default('manual');
            $table->string('note')->nullable();
            $table->dateTime('happened_at');

            // Sin updated_at a propósito: una bitácora que se puede actualizar
            // no es una bitácora.
            $table->timestamp('created_at')->nullable();

            $table->index(['invoice_id', 'happened_at']);
        });

        // Las guías que ya existen arrancan su bitácora con su estado actual,
        // para que la línea de tiempo no salga vacía.
        $ahora = now();

        foreach (DB::table('invoices')->get(['id', 'status', 'pickup_branch_id', 'created_by', 'created_at']) as $invoice) {
            DB::table('guide_status_histories')->insert([
                'invoice_id'  => $invoice->id,
                'from_status' => null,
                'to_status'   => $invoice->status,
                'branch_id'   => $invoice->pickup_branch_id,
                'user_id'     => $invoice->created_by,
                'source'      => 'system',
                'note'        => 'Estado al momento de habilitar la bitácora.',
                'happened_at' => $invoice->created_at ?? $ahora,
                'created_at'  => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_status_histories');
    }
};
