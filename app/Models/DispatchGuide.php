<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una guía dentro de un manifiesto, con su marca de recepción en destino. */
class DispatchGuide extends Model
{
    protected $fillable = [
        'dispatch_id', 'invoice_id', 'received_at', 'received_by', 'incident',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function fueRecibida(): bool
    {
        return $this->received_at !== null;
    }
}
