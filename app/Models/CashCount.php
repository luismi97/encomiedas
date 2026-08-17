<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cuántos billetes de cada denominación se contaron al cerrar. */
class CashCount extends Model
{
    protected $fillable = ['cash_session_id', 'denomination_id', 'quantity', 'subtotal'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'subtotal' => 'decimal:2'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function denomination(): BelongsTo
    {
        return $this->belongsTo(Denomination::class);
    }
}
