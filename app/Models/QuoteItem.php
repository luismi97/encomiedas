<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un bulto cotizado. */
class QuoteItem extends Model
{
    protected $fillable = [
        'quote_id', 'package_type_id', 'description',
        'weight', 'length_cm', 'width_cm', 'height_cm', 'price',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'price' => 'decimal:2',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function packageType(): BelongsTo
    {
        return $this->belongsTo(PackageType::class);
    }

    public function nombreDelBulto(): string
    {
        return $this->packageType?->name ?: 'Bulto';
    }
}
