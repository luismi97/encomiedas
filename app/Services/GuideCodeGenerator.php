<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Códigos guía con formato PREFIJO_ORIGEN-PREFIJO_DESTINO-CONSECUTIVO.
 *
 *   San José → Limón, quinta guía de esa ruta  =>  SJ-LIM-00005
 *
 * El consecutivo es por par de ruta y se reserva bajo candado en base. Contarlo
 * con un COUNT sobre invoices parece más simple hasta que dos sedes emiten en
 * el mismo segundo y las dos leen el mismo número.
 */
class GuideCodeGenerator
{
    public function generar(Branch $origen, Branch $destino): string
    {
        $prefijoOrigen  = $this->prefijo($origen);
        $prefijoDestino = $this->prefijo($destino);

        $numero = $this->reservarConsecutivo($prefijoOrigen, $prefijoDestino);
        $ancho  = max(1, (int) config('encomiendas.guide_sequence_padding', 5));

        return $prefijoOrigen . '-' . $prefijoDestino . '-' . str_pad((string) $numero, $ancho, '0', STR_PAD_LEFT);
    }

    private function prefijo(Branch $sede): string
    {
        $prefijo = strtoupper(trim((string) $sede->prefix));

        if ($prefijo === '') {
            throw new RuntimeException(
                "La sucursal «{$sede->name}» no tiene prefijo configurado y sin él no se puede armar el código guía."
            );
        }

        return $prefijo;
    }

    /**
     * Reserva el siguiente número de la ruta. La fila se bloquea para que dos
     * transacciones simultáneas no lean el mismo valor.
     */
    private function reservarConsecutivo(string $origen, string $destino): int
    {
        return DB::transaction(function () use ($origen, $destino) {
            $fila = DB::table('guide_sequences')
                ->where('origin_prefix', $origen)
                ->where('destination_prefix', $destino)
                ->lockForUpdate()
                ->first();

            if (! $fila) {
                // insertOrIgnore y no insert: si otra transacción creó la fila
                // entre el select y esto, el índice único la rechaza en vez de
                // reventar, y se relee.
                DB::table('guide_sequences')->insertOrIgnore([
                    'origin_prefix'      => $origen,
                    'destination_prefix' => $destino,
                    'last_number'        => 0,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                $fila = DB::table('guide_sequences')
                    ->where('origin_prefix', $origen)
                    ->where('destination_prefix', $destino)
                    ->lockForUpdate()
                    ->first();
            }

            $siguiente = (int) $fila->last_number + 1;

            DB::table('guide_sequences')
                ->where('id', $fila->id)
                ->update(['last_number' => $siguiente, 'updated_at' => now()]);

            return $siguiente;
        });
    }
}
