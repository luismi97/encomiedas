<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un movimiento del turno: un cobro, una entrada o una salida de efectivo. */
class CashMovement extends Model
{
    public const TYPE_SALE = 'sale'; // cobro de una guía
    public const TYPE_IN   = 'in';   // entrada de efectivo (fondo, reposición)
    public const TYPE_OUT  = 'out';  // salida (pago a proveedor, retiro)

    public const TYPES = [
        self::TYPE_SALE => 'Cobro',
        self::TYPE_IN   => 'Entrada',
        self::TYPE_OUT  => 'Salida',
    ];

    protected $fillable = [
        'cash_session_id', 'type', 'payment_method', 'amount',
        'invoice_id', 'reference', 'reason', 'created_by', 'happened_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:2',
            'happened_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function paymentMethodLabel(): string
    {
        return Invoice::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method;
    }

    /** Cuánto suma o resta al efectivo de la caja. */
    public function efectoEnEfectivo(): float
    {
        if ($this->payment_method !== 'cash') {
            return 0.0; // una tarjeta no cambia lo que hay en la gaveta
        }

        return $this->type === self::TYPE_OUT
            ? -(float) $this->amount
            : (float) $this->amount;
    }
}
