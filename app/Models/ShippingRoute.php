<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una ruta predefinida: de tal sede a tal otra, con nombre.
 *
 * Se llama ShippingRoute y no Route para no pelear con el enrutador de Laravel:
 * un `use App\Models\Route` en un archivo que también usa el facade deja un
 * error que se lee como si las rutas web estuvieran rotas.
 */
class ShippingRoute extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'name',
        'origin_branch_id',
        'destination_branch_id',
        'transit_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'transit_days' => 'integer',
        ];
    }

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Las rutas que salen de esta sede, que son las del cajero que atiende ahí. */
    public function scopeDesde(Builder $query, ?int $branchId): Builder
    {
        return $branchId ? $query->where('origin_branch_id', $branchId) : $query;
    }

    /** «SJ → LIM», que es como se lee en una etiqueta. */
    public function rutaLabel(): string
    {
        return ($this->originBranch?->prefixLabel() ?? '?')
            . ' → ' . ($this->destinationBranch?->prefixLabel() ?? '?');
    }

    /** Lo que se muestra en el desplegable: la ruta y su nombre propio. */
    public function etiqueta(): string
    {
        return $this->rutaLabel() . ' · ' . $this->name;
    }

    public function transitoLabel(): ?string
    {
        if (! $this->transit_days) {
            return null;
        }

        return $this->transit_days === 1 ? '1 día' : "{$this->transit_days} días";
    }

    /**
     * Cuándo debería llegar una encomienda que sale en esta fecha.
     *
     * Días de calendario y no hábiles: los camiones salen sábado, y prometer
     * una fecha que ignore el fin de semana sería prometer de más justo cuando
     * el cliente está preguntando por qué no ha llegado.
     */
    public function llegadaEstimadaDesde(?Carbon $salida = null): ?Carbon
    {
        if (! $this->transit_days) {
            return null;
        }

        return ($salida ?? now())->copy()->addDays($this->transit_days);
    }
}
