<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // A dónde se manda el reporte contable. Se recuerda el último usado
            // para no digitarlo cada mes.
            $table->string('accountant_email', 150)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('accountant_email');
        });
    }
};
