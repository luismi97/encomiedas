<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use Illuminate\Support\Carbon;

/**
 * Reporte para el contador: cuánto se vendió en un período y cuánto IVA hay
 * que declarar.
 *
 * Solo cuenta comprobantes ACEPTADOS por Hacienda y del ambiente de
 * producción: un rechazado no existe para Hacienda, y uno de sandbox es una
 * prueba. Lo que no se aceptó no se declara.
 *
 * Fecha = fecha de emisión del comprobante (la que Hacienda usa para el
 * período de la declaración), no la de la guía ni la de aceptación.
 *
 * Las notas de crédito restan y las de débito suman: una venta anulada en el
 * mes se declara neta.
 *
 * Devuelve arreglos planos (sin modelos) para que el mismo resultado sirva a
 * la pantalla, al PDF y al correo en cola.
 */
class ReporteContable
{
    private const TIPOS = [
        '01' => 'Facturas electrónicas',
        '04' => 'Tiquetes electrónicos',
        '02' => 'Notas de débito',
        '03' => 'Notas de crédito',
    ];

    /**
     * @return array{
     *   desde:string, hasta:string, sede:?string, empresa:string, cedula:?string,
     *   porTipo:array<int,array{tipo:string, cantidad:int, venta:float, iva:float, total:float}>,
     *   porTarifa:array<int,array{tarifa:string, venta:float, iva:float}>,
     *   neto:array{venta:float, iva:float, total:float},
     *   detalle:array<int,array<string,mixed>>,
     *   pendientes:int, deSandbox:int
     * }
     */
    public function generar(string $desde, string $hasta, ?int $sedeId = null): array
    {
        $inicio = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($hasta)->endOfDay();

        $base = fn () => ElectronicInvoice::query()
            ->whereBetween('issued_at', [$inicio, $fin])
            ->when($sedeId, fn ($q) => $q->where('branch_id', $sedeId));

        $aceptados = $base()
            ->where('status', ElectronicInvoice::STATUS_ACCEPTED)
            ->where('environment', 'prod')
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        $detalle = $aceptados->map(function (ElectronicInvoice $c) {
            // Las notas de crédito restan. Todo en colones aunque se emitiera
            // en otra moneda.
            $signo = $c->document_type === '03' ? -1 : 1;
            $cambio = (float) ($c->exchange_rate ?: 1);
            $venta = round($signo * (float) $c->sub_total * $cambio, 2);
            $iva = round($signo * (float) $c->total_tax * $cambio, 2);

            return [
                'fecha'       => $c->issued_at?->format('d/m/Y'),
                'tipo'        => $c->document_type,
                'tipoNombre'  => $c->typeLabel(),
                'consecutivo' => $c->consecutivo,
                'clave'       => $c->clave,
                'receptor'    => $c->receptor_data['nombre'] ?? 'Consumidor final',
                'cedula'      => $c->receptor_data['numero'] ?? null,
                'tarifa'      => (float) $c->total_exonerated > 0
                    // Exonerado no es exento: el contador lo declara aparte.
                    ? 'Exonerado'
                    : $this->tarifa((float) $c->sub_total, (float) $c->total_tax),
                'venta'       => $venta,
                'iva'         => $iva,
                'total'       => round($venta + $iva, 2),
            ];
        });

        $porTipo = collect(self::TIPOS)
            ->map(function (string $nombre, string $codigo) use ($detalle) {
                $filas = $detalle->where('tipo', $codigo);

                return [
                    'tipo'     => $nombre,
                    'cantidad' => $filas->count(),
                    'venta'    => round($filas->sum('venta'), 2),
                    'iva'      => round($filas->sum('iva'), 2),
                    'total'    => round($filas->sum('total'), 2),
                ];
            })
            ->filter(fn ($fila) => $fila['cantidad'] > 0)
            ->values()
            ->all();

        $porTarifa = $detalle->groupBy('tarifa')
            ->map(fn ($filas, $tarifa) => [
                'tarifa' => $tarifa,
                'venta'  => round($filas->sum('venta'), 2),
                'iva'    => round($filas->sum('iva'), 2),
            ])
            ->sortKeysDesc()
            ->values()
            ->all();

        $empresa = CompanySetting::instance();

        return [
            'desde'      => $inicio->format('d/m/Y'),
            'hasta'      => $fin->format('d/m/Y'),
            'sede'       => $sedeId ? Branch::find($sedeId)?->name : null,
            'empresa'    => (string) ($empresa->name ?: $empresa->commercial_name),
            'cedula'     => $empresa->identification_number,
            'porTipo'    => $porTipo,
            'porTarifa'  => $porTarifa,
            'neto'       => [
                'venta' => round($detalle->sum('venta'), 2),
                'iva'   => round($detalle->sum('iva'), 2),
                'total' => round($detalle->sum('total'), 2),
            ],
            'detalle'    => $detalle->values()->all(),
            // No entran, pero el contador tiene que saber que existen: si
            // Hacienda los acepta después, el período cambia.
            'pendientes' => $base()
                ->where('environment', 'prod')
                ->whereNotIn('status', [ElectronicInvoice::STATUS_ACCEPTED, ElectronicInvoice::STATUS_REJECTED])
                ->count(),
            'deSandbox'  => $base()->where('environment', '!=', 'prod')->count(),
        ];
    }

    /** La tarifa de IVA del comprobante, despejada de sus propios montos. */
    private function tarifa(float $venta, float $iva): string
    {
        if ($venta <= 0 || $iva <= 0) {
            return 'Exento (0%)';
        }

        return 'IVA ' . rtrim(rtrim(number_format(round($iva / $venta * 100, 2), 2), '0'), '.') . '%';
    }
}
