<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turno de caja: de la apertura al arqueo.
 *
 * expected_cash es lo que el sistema dice que debería haber en efectivo;
 * counted_cash es lo que el cajero contó. La diferencia es lo que se investiga.
 */
class CashSession extends Model
{
    use BelongsToBranch;

    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'cash_register_id', 'branch_id',
        'opened_by', 'opened_at', 'opening_float',
        'closed_by', 'closed_at',
        'expected_cash', 'counted_cash', 'discrepancy',
        'status', 'closing_note',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected function casts(): array
    {
        return [
            'opened_at'     => 'datetime',
            'closed_at'     => 'datetime',
            'opening_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash'  => 'decimal:2',
            'discrepancy'   => 'decimal:2',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('happened_at');
    }

    public function counts(): HasMany
    {
        return $this->hasMany(CashCount::class);
    }

    public function estaAbierta(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function hayFaltante(): bool
    {
        return (float) $this->discrepancy < 0;
    }

    public function haySobrante(): bool
    {
        return (float) $this->discrepancy > 0;
    }

    public function cuadra(): bool
    {
        return abs((float) $this->discrepancy) < 0.01;
    }
}
