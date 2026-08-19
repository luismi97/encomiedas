<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Qué es el bulto: paquete, caja, sobre, herramienta.
 *
 * Configurable porque cada operación recibe cosas distintas, y una lista
 * quemada en el código obligaría a un despliegue para agregar «llanta».
 */
class PackageType extends Model
{
    protected $fillable = ['name', 'is_fragile', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_fragile' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /** El tipo que aparece preseleccionado al agregar un bulto. */
    public static function porDefecto(): ?self
    {
        return static::active()->first();
    }
}
