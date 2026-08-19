<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'package_type_id',
        'package_code',
        'size',
        'weight',
        'length_cm',
        'width_cm',
        'height_cm',
        'description',
        'price',
        'cabys_code',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'price' => 'decimal:5',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function packageType(): BelongsTo
    {
        return $this->belongsTo(PackageType::class);
    }

    /**
     * Cómo se nombra este bulto en recibos, etiquetas y comprobantes.
     *
     * Los renglones viejos traen el código que se digitaba a mano; los nuevos,
     * el tipo. Se resuelve en un solo lugar para que las cinco pantallas que lo
     * imprimen no tengan cada una su propio condicional.
     */
    public function nombreDelBulto(): string
    {
        return $this->packageType?->name
            ?: ($this->package_code ?: 'Bulto');
    }

    public function esFragil(): bool
    {
        return (bool) $this->packageType?->is_fragile;
    }
}
