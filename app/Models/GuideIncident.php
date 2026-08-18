<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incidencia sobre una guía.
 *
 * No cambia el estado a propósito: un destinatario ausente deja la encomienda
 * donde está, y hay que poder registrar el intento fallido sin mover el ciclo.
 */
class GuideIncident extends Model
{
    public const TYPE_DAMAGED  = 'damaged';
    public const TYPE_ADDRESS  = 'wrong_address';
    public const TYPE_ABSENT   = 'recipient_absent';
    public const TYPE_LOST     = 'lost';
    public const TYPE_DELAYED  = 'delayed';
    public const TYPE_OTHER    = 'other';

    public const TYPES = [
        self::TYPE_DAMAGED => 'Paquete dañado',
        self::TYPE_ADDRESS => 'Dirección incorrecta',
        self::TYPE_ABSENT  => 'Destinatario ausente',
        self::TYPE_LOST    => 'Extravío',
        self::TYPE_DELAYED => 'Retraso',
        self::TYPE_OTHER   => 'Otro',
    ];

    public const TYPE_BADGE_CLASSES = [
        self::TYPE_DAMAGED => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
        self::TYPE_ADDRESS => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        self::TYPE_ABSENT  => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        self::TYPE_LOST    => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
        self::TYPE_DELAYED => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
        self::TYPE_OTHER   => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    ];

    protected $fillable = [
        'invoice_id', 'type', 'description', 'branch_id',
        'reported_by', 'reported_at', 'resolution', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function estaResuelta(): bool
    {
        return $this->resolved_at !== null;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function badgeClasses(): string
    {
        return self::TYPE_BADGE_CLASSES[$this->type] ?? self::TYPE_BADGE_CLASSES[self::TYPE_OTHER];
    }

    /** Días que lleva abierta. Sirve para priorizar el seguimiento. */
    public function diasAbierta(): int
    {
        return (int) $this->reported_at->diffInDays($this->resolved_at ?? now());
    }
}
