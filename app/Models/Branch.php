<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sucursal_code',
        'terminal_code',
        'address',
        'province',
        'canton',
        'district',
        'phone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function pickupInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'pickup_branch_id');
    }

    public function deliveryInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'delivery_branch_id');
    }
}
