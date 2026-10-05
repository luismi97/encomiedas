<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Devolver es del administrador y con motivo, igual que anular.
            $table->string('return_reason', 500)->nullable()->after('returned_at');
            $table->foreignId('returned_by')->nullable()->after('return_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn('return_reason');
        });
    }
};
