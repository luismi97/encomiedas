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
}
