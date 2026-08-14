<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('document_type', 2); // 01 FE, 04 TE, 03 NC, 02 ND
            $table->string('clave', 50)->unique();
            $table->string('consecutivo', 20);
            $table->string('security_code', 8);
            $table->string('environment', 10);
            $table->dateTime('issued_at');

            $table->json('emisor_data')->nullable();
            $table->json('receptor_data')->nullable();

            $table->string('currency_code', 3)->default('CRC');
            $table->double('exchange_rate')->default(1);

            $table->double('sub_total')->default(0);
            $table->double('total_tax')->default(0);
            $table->double('total_discount')->default(0);
            $table->double('total_other_charges')->default(0);
            $table->double('total')->default(0);

            // pending: en la lista de "pendientes de envío" | sending | sent | accepted | rejected | error
            $table->string('status', 20)->default('pending');
            $table->string('hacienda_status')->nullable();
            $table->string('signed_xml_path')->nullable();
            $table->string('response_xml_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('send_attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('accepted_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_invoices');
    }
};
