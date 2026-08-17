<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila por cambio de estado de una guía. Se escribe y no se toca más:
 * el modelo no expone updated_at y nada en la aplicación la edita.
 */
class GuideStatusHistory extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SCAN   = 'scan';
    public const SOURCE_SYSTEM = 'system';

    public const SOURCES = [
        self::SOURCE_MANUAL => 'Manual',
        self::SOURCE_SCAN   => 'Escaneo',
        self::SOURCE_SYSTEM => 'Automático',
    ];

    /** Sin updated_at: una bitácora que se actualiza no es una bitácora. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id',
        'from_status',
        'to_status',
        'branch_id',
        'user_id',
        'source',
        'note',
        'happened_at',
    ];

    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromLabel(): ?string
    {
        return $this->from_status ? (Invoice::STATUSES[$this->from_status] ?? $this->from_status) : null;
    }

    public function toLabel(): string
    {
        return Invoice::STATUSES[$this->to_status] ?? $this->to_status;
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }
}
