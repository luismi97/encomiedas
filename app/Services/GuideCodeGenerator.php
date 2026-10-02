<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Códigos guía con formato PREFIJO_ORIGEN-PREFIJO_DESTINO-CONSECUTIVO.
 *
 *   San José → Limón, quinta guía de esa ruta  =>  SJ-LIM-00005
 *
 * El consecutivo es por par de ruta y se reserva bajo candado en base. Contarlo
 * con un COUNT sobre invoices parece más simple hasta que dos sedes emiten en
 * el mismo segundo y las dos leen el mismo número.
 *
 * Y es GLOBAL, compartido por todas las empresas (guide_code_sequences): un
 * código guía identifica una sola encomienda en todo el sistema. Fue por
 * empresa hasta octubre de 2026; dos empresas con una sede «SJ» emitían las dos
 * SJ-LIM-00005. Cada empresa ve ahora saltos en su numeración, y es a propósito.
 */
class GuideCodeGenerator
{
    public function generar(Branch $origen, Branch $destino): string
    {
        $prefijoOrigen  = $this->prefijo($origen);
        $prefijoDestino = $this->prefijo($destino);

        $ancho = max(1, (int) config('encomiendas.guide_sequence_padding', 5));

        return $this->reservarConsecutivo(
            $prefijoOrigen,
            $prefijoDestino,
            fn (int $numero) => $prefijoOrigen . '-' . $prefijoDestino . '-' . str_pad((string) $numero, $ancho, '0', STR_PAD_LEFT),
        );
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
     * Reserva el siguiente código libre de la ruta. La fila se bloquea para que
     * dos transacciones simultáneas —de esta empresa o de otra— no lean el mismo
     * valor.
     *
     * @param callable(int):string $formato
     */
    private function reservarConsecutivo(string $origen, string $destino, callable $formato): string
    {
        return DB::transaction(function () use ($origen, $destino, $formato) {
            $fila = DB::table('guide_code_sequences')
                ->where('origin_prefix', $origen)
                ->where('destination_prefix', $destino)
                ->lockForUpdate()
                ->first();

            if (! $fila) {
                // insertOrIgnore y no insert: si otra transacción creó la fila
                // entre el select y esto, el índice único la rechaza en vez de
                // reventar, y se relee.
                DB::table('guide_code_sequences')->insertOrIgnore([
                    'origin_prefix'      => $origen,
                    'destination_prefix' => $destino,
                    'last_number'        => 0,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                $fila = DB::table('guide_code_sequences')
                    ->where('origin_prefix', $origen)
                    ->where('destination_prefix', $destino)
                    ->lockForUpdate()
                    ->first();
            }

            $siguiente = (int) $fila->last_number + 1;

            // Si el contador quedó detrás de las guías que ya existen —en
            // cualquier empresa—, el código que toca ya está usado. Se salta al
            // siguiente libre y el contador queda al día. DB::table y no el
            // modelo: el ámbito por empresa escondería justamente las guías
            // ajenas con las que no se puede chocar.
            $saltados = 0;

            while (DB::table('invoices')->where('code', $formato($siguiente))->exists()) {
                $siguiente++;
                $saltados++;
            }

            if ($saltados > 0) {
                Log::warning("Contador de guías atrasado en la ruta {$origen}-{$destino}: "
                    . "se saltaron {$saltados} códigos ya usados y se siguió en el {$siguiente}.");
            }

            DB::table('guide_code_sequences')
                ->where('id', $fila->id)
                ->update(['last_number' => $siguiente, 'updated_at' => now()]);

            return $formato($siguiente);
        });
    }
}
