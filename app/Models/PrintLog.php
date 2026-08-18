<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una impresión de etiqueta. Se escribe y no se toca: es evidencia. */
class PrintLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['invoice_id', 'user_id', 'copy_number', 'paper_width', 'ip'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function esReimpresion(): bool
    {
        return $this->copy_number > 1;
    }
}
