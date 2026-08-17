<?php

use App\Models\Invoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La columna era un ENUM con los cinco estados originales, así que MySQL
     * rechazaba los cinco nuevos con «Data truncated for column 'status'».
     *
     * Se cambia a string en vez de ampliar el ENUM: cada estado nuevo obligaría
     * a otra migración con ALTER sobre toda la tabla, y quien valida las
     * transiciones es Invoice::TRANSITIONS, no la base.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // SQLite y Postgres no imponen el ENUM
        }

        DB::statement("ALTER TABLE invoices MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Los estados nuevos no caben en el ENUM viejo: se llevan al más cercano
        // para que el ALTER no los trunque a ciegas.
        DB::table('invoices')->whereIn('status', [Invoice::STATUS_READY])
            ->update(['status' => Invoice::STATUS_PENDING]);
        DB::table('invoices')->whereIn('status', [Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION])
            ->update(['status' => Invoice::STATUS_IN_TRANSIT]);
        DB::table('invoices')->whereIn('status', [Invoice::STATUS_NEAR_DISPOSAL, Invoice::STATUS_DISPOSED])
            ->update(['status' => Invoice::STATUS_CANCELLED]);

        DB::statement(
            "ALTER TABLE invoices MODIFY status ENUM('pending','in_transit','delivered','returned','cancelled') NOT NULL DEFAULT 'pending'"
        );
    }
};
