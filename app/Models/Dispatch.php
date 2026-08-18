<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cierre de envío: el manifiesto que viaja con el camión.
 *
 * Agrupa las guías que salen en un viaje y, al recibirse en destino, permite
 * marcar una por una. Lo que quede sin marcar es un faltante — que es el punto
 * del documento, no la lista en sí.
 */
class Dispatch extends Model
{
    use HasFactory;
    use BelongsToBranch;

    /** Un cierre lo arma la sede origen y lo recibe la destino. */
    public function branchColumns(): array
    {
        return ['origin_branch_id', 'destination_branch_id'];
    }

    public const STATUS_OPEN       = 'open';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_RECEIVED   = 'received';

    public const STATUSES = [
        self::STATUS_OPEN       => 'En preparación',
        self::STATUS_DISPATCHED => 'En ruta',
        self::STATUS_RECEIVED   => 'Recibido en destino',
    ];

    public const STATUS_BADGE_CLASSES = [
        self::STATUS_OPEN       => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200',
        self::STATUS_DISPATCHED => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
        self::STATUS_RECEIVED   => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
    ];

    protected $fillable = [
        'code', 'origin_branch_id', 'destination_branch_id',
        'driver_name', 'vehicle_plate', 'status',
        'departed_at', 'received_at',
        'created_by', 'dispatched_by', 'received_by', 'notes',
    ];

    /**
     * El default de la columna lo aplica la base, y un modelo recién creado no
     * lo tiene en memoria: sin esto, $manifiesto->status es null justo después
     * del create() y estaAbierto() responde que no.
     */
    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected function casts(): array
    {
        return [
            'departed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DispatchGuide::class);
    }

    public function guides(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'dispatch_guides', 'dispatch_id', 'invoice_id')
            ->withPivot(['received_at', 'received_by', 'incident'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function estaAbierto(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function enRuta(): bool
    {
        return $this->status === self::STATUS_DISPATCHED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function badgeClasses(): string
    {
        return self::STATUS_BADGE_CLASSES[$this->status] ?? '';
    }

    public function rutaLabel(): string
    {
        return ($this->originBranch?->prefixLabel() ?? '?') . ' → ' . ($this->destinationBranch?->prefixLabel() ?? '?');
    }

    // ── Totales del manifiesto ────────────────────────────────────────

    public function totalPaquetes(): int
    {
        return (int) $this->guides->sum(fn (Invoice $g) => $g->items->count());
    }

    public function pesoTotal(): float
    {
        return round((float) $this->guides->sum(fn (Invoice $g) => $g->items->sum('weight')), 2);
    }

    public function valorDeclaradoTotal(): float
    {
        return round((float) $this->guides->sum('declared_value'), 2);
    }

    /** Guías del manifiesto que nadie marcó al recibir: los faltantes. */
    public function faltantes()
    {
        return $this->lines->whereNull('received_at');
    }

    public function recibidas()
    {
        return $this->lines->whereNotNull('received_at');
    }
}
