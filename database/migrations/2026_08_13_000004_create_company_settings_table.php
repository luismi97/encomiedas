<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Datos de la empresa emisora + credenciales de Hacienda. Fila única (singleton).
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('enabled')->default(false);
            $table->enum('environment', ['sandbox', 'prod'])->default('sandbox');

            $table->string('name')->nullable();
            $table->string('commercial_name')->nullable();
            $table->string('identification_type', 2)->nullable(); // 01 física, 02 jurídica, 03 DIMEX, 04 NITE
            $table->string('identification_number')->nullable();
            $table->string('activity_code', 6)->nullable();

            $table->string('province', 1)->nullable();
            $table->string('canton', 2)->nullable();
            $table->string('district', 2)->nullable();
            $table->string('barrio')->nullable();
            $table->string('others_signs')->nullable();

            $table->string('phone_code', 3)->default('506');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->text('atv_username')->nullable();
            $table->text('atv_password')->nullable();
            $table->string('certificate_path')->nullable();
            $table->text('certificate_pin')->nullable();

            $table->string('default_cabys_code', 13)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
