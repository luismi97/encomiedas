<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Caja física de una sede. Una sede puede tener varias. */
class CashRegister extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashSession::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** El turno abierto, si hay alguno. */
    public function sesionAbierta(): ?CashSession
    {
        return $this->sessions()->where('status', CashSession::STATUS_OPEN)->latest('id')->first();
    }

    public function estaAbierta(): bool
    {
        return $this->sesionAbierta() !== null;
    }
}
