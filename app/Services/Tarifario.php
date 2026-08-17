<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Rate;

/**
 * Resuelve cuánto cobrar por transportar un paquete.
 *
 * El peso que manda no siempre es el de la balanza: un paquete grande y liviano
 * ocupa espacio en el camión igual que uno pesado, así que la industria cobra
 * por el mayor entre el peso real y el volumétrico.
 */
class Tarifario
{
    /**
     * Peso volumétrico en kg a partir de las dimensiones en centímetros.
     *
     * El divisor es una convención del sector (5000 para aéreo, 6000 para
     * terrestre); vive en configuración porque cada empresa usa el suyo.
     */
    public function pesoVolumetrico(?float $largo, ?float $ancho, ?float $alto): float
    {
        if (!$largo || !$ancho || !$alto) {
            return 0.0;
        }

        $divisor = (float) config('encomiendas.volumetric_divisor', 5000);

        if ($divisor <= 0) {
            return 0.0;
        }

        return round(($largo * $ancho * $alto) / $divisor, 2);
    }

    /** El que se cobra: el mayor entre el real y el volumétrico. */
    public function pesoFacturable(float $pesoReal, ?float $largo = null, ?float $ancho = null, ?float $alto = null): float
    {
        return round(max($pesoReal, $this->pesoVolumetrico($largo, $ancho, $alto)), 2);
    }

    /**
     * Tarifa aplicable, o null si no hay ninguna que cubra el caso.
     *
     * Devolver null es deliberado: es preferible que el cajero digite el precio
     * a mano y se entere de que falta una tarifa, a cobrar un cero silencioso.
     */
    public function buscar(
        ?Branch $origen,
        ?Branch $destino,
        float $peso,
        ?string $tipoEnvio = null
    ): ?Rate {
        $candidatas = Rate::query()
            ->active()
            ->where(fn ($q) => $q->whereNull('origin_branch_id')->orWhere('origin_branch_id', $origen?->id))
            ->where(fn ($q) => $q->whereNull('destination_branch_id')->orWhere('destination_branch_id', $destino?->id))
            ->where(fn ($q) => $q->whereNull('shipment_type')->orWhere('shipment_type', $tipoEnvio))
            ->get()
            ->filter(fn (Rate $r) => $r->cubrePeso($peso));

        // Gana la más específica; entre iguales, la de rango de peso más
        // estrecho, que es la pensada para ese caso.
        return $candidatas
            ->sortByDesc(fn (Rate $r) => [$r->especificidad(), -(float) $r->min_weight])
            ->first();
    }

    /**
     * Cotización completa para mostrar en el formulario de guía.
     *
     * @return array{peso_real:float,peso_volumetrico:float,peso_facturable:float,tarifa:?Rate,precio:?float,motivo:?string}
     */
    public function cotizar(
        ?Branch $origen,
        ?Branch $destino,
        float $pesoReal,
        ?float $largo = null,
        ?float $ancho = null,
        ?float $alto = null,
        ?string $tipoEnvio = null
    ): array {
        $volumetrico = $this->pesoVolumetrico($largo, $ancho, $alto);
        $facturable  = round(max($pesoReal, $volumetrico), 2);

        $tarifa = $this->buscar($origen, $destino, $facturable, $tipoEnvio);

        return [
            'peso_real'        => round($pesoReal, 2),
            'peso_volumetrico' => $volumetrico,
            'peso_facturable'  => $facturable,
            'tarifa'           => $tarifa,
            'precio'           => $tarifa?->precioPara($facturable),
            'motivo'           => $tarifa
                ? null
                : 'No hay tarifa configurada para esta ruta y peso. Digite el precio manualmente.',
        ];
    }
}
