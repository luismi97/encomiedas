<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remitentes y destinatarios registrados.
 *
 * Hasta ahora el remitente y el destinatario eran texto libre en cada guía, así
 * que no había forma de acumular envíos por cliente ni de facturar a crédito.
 */
class Customer extends Model
{
    use HasFactory;

    public const PAYMENT_CASH   = 'cash';
    public const PAYMENT_CREDIT = 'credit';

    public const PAYMENT_CONDITIONS = [
        self::PAYMENT_CASH   => 'Contado',
        self::PAYMENT_CREDIT => 'Crédito',
    ];

    /** Mismo catálogo del receptor en Hacienda. */
    public const IDENTIFICATION_TYPES = [
        '01' => 'Física',
        '02' => 'Jurídica',
        '03' => 'DIMEX',
        '04' => 'NITE',
    ];

    protected $fillable = [
        'name',
        'commercial_name',
        'identification_type',
        'identification',
        'activity_code',
        'email',
        'phone',
        'address',
        'branch_id',
        'payment_condition',
        'credit_limit',
        'credit_cutoff_day',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'credit_limit' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCredit(Builder $query): Builder
    {
        return $query->where('payment_condition', self::PAYMENT_CREDIT);
    }

    public function isCredit(): bool
    {
        return $this->payment_condition === self::PAYMENT_CREDIT;
    }

    public function paymentConditionLabel(): string
    {
        return self::PAYMENT_CONDITIONS[$this->payment_condition] ?? self::PAYMENT_CONDITIONS[self::PAYMENT_CASH];
    }

    public function identificationTypeLabel(): ?string
    {
        return self::IDENTIFICATION_TYPES[$this->identification_type] ?? null;
    }

    /**
     * ¿Se le puede emitir Factura Electrónica?
     *
     * Hacienda exige receptor identificado; sin cédula el comprobante tiene que
     * salir como Tiquete Electrónico.
     */
    public function puedeFacturaElectronica(): bool
    {
        return filled($this->identification) && filled($this->identification_type);
    }

    /** Cómo se muestra en listados y buscadores. */
    public function displayName(): string
    {
        return $this->identification
            ? $this->name . ' · ' . $this->identification
            : $this->name;
    }
}
