<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'prefix',
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

    public function sequences(): HasMany
    {
        return $this->hasMany(ElectronicBillingSequence::class);
    }

    /** Encomiendas que todavia no llegaron a un estado final. */
    public function inProgressInvoices(): Builder
    {
        return Invoice::query()
            ->where(fn (Builder $q) => $q->where('pickup_branch_id', $this->id)->orWhere('delivery_branch_id', $this->id))
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_IN_TRANSIT]);
    }

    /** Cualquier encomienda ligada a la sucursal, en cualquier estado. */
    public function allInvoices(): Builder
    {
        return Invoice::query()
            ->where(fn (Builder $q) => $q->where('pickup_branch_id', $this->id)->orWhere('delivery_branch_id', $this->id));
    }

    /**
     * El consecutivo de Hacienda se construye con sucursal + terminal. Si ya se
     * emitio un comprobante con estos codigos, cambiarlos rompe la numeracion.
     */
    public function hasHaciendaHistory(): bool
    {
        return $this->sequences()->where('last_number', '>', 0)->exists();
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** Prefijo del código guía (SJ-LIM-00005), en mayúsculas. */
    public function prefixLabel(): string
    {
        return strtoupper((string) $this->prefix);
    }

    public function codeLabel(): string
    {
        return $this->sucursal_code . '-' . $this->terminal_code;
    }
}
