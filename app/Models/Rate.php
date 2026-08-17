<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarifa de transporte por ruta y rango de peso.
 *
 * Origen, destino y tipo de envío en null significan "cualquiera": así se puede
 * tener una tarifa base sin declarar todas las combinaciones de sedes.
 */
class Rate extends Model
{
    use HasFactory;

    public const TYPE_PACKAGE  = 'package';
    public const TYPE_ENVELOPE = 'envelope';
    public const TYPE_DOCUMENT = 'document';

    public const SHIPMENT_TYPES = [
        self::TYPE_PACKAGE  => 'Paquete',
        self::TYPE_ENVELOPE => 'Sobre',
        self::TYPE_DOCUMENT => 'Documento',
    ];

    protected $fillable = [
        'name',
        'origin_branch_id',
        'destination_branch_id',
        'shipment_type',
        'min_weight',
        'max_weight',
        'price',
        'price_per_extra_kg',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'          => 'boolean',
            'min_weight'         => 'decimal:2',
            'max_weight'         => 'decimal:2',
            'price'              => 'decimal:2',
            'price_per_extra_kg' => 'decimal:2',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Qué tan específica es esta tarifa. Sirve para desempatar cuando varias
     * aplican: gana la que declara más condiciones, no la primera que aparezca.
     */
    public function especificidad(): int
    {
        return ($this->origin_branch_id ? 4 : 0)
            + ($this->destination_branch_id ? 2 : 0)
            + ($this->shipment_type ? 1 : 0);
    }

    public function cubrePeso(float $peso): bool
    {
        // Extremo superior exclusivo: con rangos 0–1 y 1–5, un kilo exacto cae
        // en el segundo y no en los dos.
        return $peso >= (float) $this->min_weight
            && ($this->max_weight === null || $peso < (float) $this->max_weight);
    }

    /** Cobro para un peso dado, incluyendo el excedente del tramo abierto. */
    public function precioPara(float $peso): float
    {
        $precio = (float) $this->price;

        if ($this->max_weight !== null || (float) $this->price_per_extra_kg <= 0) {
            return round($precio, 2);
        }

        // Tramo sin tope: se cobra el precio base más los kilos que pasen del
        // mínimo, redondeados hacia arriba (nadie cobra medio kilo extra).
        $excedente = max(0, ceil($peso - (float) $this->min_weight));

        return round($precio + $excedente * (float) $this->price_per_extra_kg, 2);
    }

    public function rutaLabel(): string
    {
        $origen  = $this->originBranch?->prefix ?? 'Cualquiera';
        $destino = $this->destinationBranch?->prefix ?? 'Cualquiera';

        return $origen . ' → ' . $destino;
    }

    public function pesoLabel(): string
    {
        $min = rtrim(rtrim(number_format((float) $this->min_weight, 2), '0'), '.');

        if ($this->max_weight === null) {
            return $min . ' kg o más';
        }

        $max = rtrim(rtrim(number_format((float) $this->max_weight, 2), '0'), '.');

        return $min . ' – ' . $max . ' kg';
    }

    public function shipmentTypeLabel(): string
    {
        return self::SHIPMENT_TYPES[$this->shipment_type] ?? 'Todos';
    }
}
