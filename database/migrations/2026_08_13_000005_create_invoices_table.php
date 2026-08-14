<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // ENC-000001

            // pending: recien creada | in_transit: en camino | delivered: entregada
            // returned: devuelta | cancelled: anulada
            $table->enum('status', ['pending', 'in_transit', 'delivered', 'returned', 'cancelled'])
                ->default('pending');

            $table->foreignId('pickup_branch_id')->constrained('branches');
            $table->foreignId('delivery_branch_id')->constrained('branches');

            $table->string('sender_name');
            $table->string('sender_phone')->nullable();
            $table->string('sender_identification')->nullable();

            $table->string('recipient_name');
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_identification_type', 2)->nullable();
            $table->string('recipient_identification')->nullable();
            $table->string('recipient_email')->nullable();

            $table->decimal('subtotal', 12, 5)->default(0);
            $table->decimal('discount_amount', 12, 5)->default(0);
            $table->decimal('tax_total', 12, 5)->default(0);
            $table->decimal('total', 12, 5)->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('returned_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
