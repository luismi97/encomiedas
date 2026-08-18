<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Abono a la cuenta de crédito de un cliente. */
class CreditPayment extends Model
{
    protected $fillable = [
        'customer_id', 'credit_statement_id', 'amount',
        'payment_method', 'reference', 'paid_at', 'received_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CreditStatement::class, 'credit_statement_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function paymentMethodLabel(): string
    {
        return Invoice::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method;
    }
}
