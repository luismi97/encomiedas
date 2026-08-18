<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada impresión de etiqueta queda registrada. El requisito lo pide
        // contra el fraude de la doble etiqueta: dos rótulos iguales pegados en
        // dos paquetes distintos, y uno viaja sin pagar.
        Schema::create('print_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('copy_number'); // 1 = original
            $table->unsignedSmallInteger('paper_width');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_logs');
    }
};
