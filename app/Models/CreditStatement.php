<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estado de cuenta de un período de crédito.
 *
 * Agrupa las guías que el cliente acumuló entre dos cortes. Es la unidad que se
 * cobra y, cuando corresponde, la que se factura electrónicamente.
 */
class CreditStatement extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PAID   = 'paid';

    public const STATUSES = [
        self::STATUS_ISSUED => 'Por cobrar',
        self::STATUS_PAID   => 'Saldado',
    ];

    protected $fillable = [
        'code', 'customer_id', 'period_start', 'period_end', 'due_date',
        'total', 'paid', 'balance', 'status', 'issued_by', 'issued_at', 'notes',
    ];

    protected $attributes = [
        'status' => self::STATUS_ISSUED,
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end'   => 'date',
            'due_date'     => 'date',
            'issued_at'    => 'datetime',
            'total'        => 'decimal:2',
            'paid'         => 'decimal:2',
            'balance'      => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function guides(): HasMany
    {
        return $this->hasMany(Invoice::class, 'credit_statement_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CreditPayment::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    public function estaSaldado(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Días vencidos. Cero si aún no vence o ya se saldó. */
    public function diasVencido(): int
    {
        if ($this->estaSaldado() || ! $this->due_date || $this->due_date->isFuture()) {
            return 0;
        }

        return (int) $this->due_date->diffInDays(now());
    }

    public function estaVencido(): bool
    {
        return $this->diasVencido() > 0;
    }

    /**
     * Tramo de antigüedad, como lo pide el reporte de cuentas por cobrar.
     */
    public function tramoAntiguedad(): string
    {
        $dias = $this->diasVencido();

        return match (true) {
            $dias === 0  => 'Al día',
            $dias <= 30  => '1 – 30 días',
            $dias <= 60  => '31 – 60 días',
            $dias <= 90  => '61 – 90 días',
            default      => 'Más de 90 días',
        };
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function periodoLabel(): string
    {
        return $this->period_start->format('d/m/Y') . ' – ' . $this->period_end->format('d/m/Y');
    }
}
