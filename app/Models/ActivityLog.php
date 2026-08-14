<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de acciones de usuarios (principalmente cambios de estado hechos
 * por repartidores) para trazabilidad.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'invoice_id',
        'action',
        'description',
        'old_value',
        'new_value',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Registra una acción del usuario autenticado. */
    public static function record(string $action, string $description, ?Invoice $invoice = null, ?string $oldValue = null, ?string $newValue = null): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'invoice_id' => $invoice?->id,
            'action' => $action,
            'description' => $description,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);
    }
}
