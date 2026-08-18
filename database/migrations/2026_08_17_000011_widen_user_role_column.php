<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La columna era un ENUM de dos valores, así que agregar «cajero» la
     * rompía: MySQL truncaba y SQLite fallaba el CHECK.
     *
     * Se cambia a string en vez de ampliar el ENUM porque cada rol nuevo del
     * requisito (operador de bodega, chofer, supervisor) obligaría a otro ALTER
     * sobre la tabla entera, y quien valida los roles es User::ROLES.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'repartidor'");

            return;
        }

        // SQLite no admite MODIFY: se recrea la columna con el helper de Laravel.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('repartidor')->change();
        });
    }

    public function down(): void
    {
        // Los roles nuevos no caben en el ENUM viejo.
        DB::table('users')->whereNotIn('role', [User::ROLE_ADMIN, User::ROLE_REPARTIDOR])
            ->update(['role' => User::ROLE_REPARTIDOR]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','repartidor') NOT NULL DEFAULT 'repartidor'");
        }
    }
};
