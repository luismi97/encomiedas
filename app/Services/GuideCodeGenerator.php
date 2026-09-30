<?php

namespace App\Services;

use App\Models\Branch;
use App\Support\CompanyContext;
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
 * Y es por empresa: dos empresas distintas usan «SJ» para su sede de San José,
 * y sin separarlas la guía número 1 de la segunda empresa saldría numerada
 * donde quedó la primera —o chocaría contra el índice único—.
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
            $origen->company_id,
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
     * dos transacciones simultáneas no lean el mismo valor.
     *
     * @param callable(int):string $formato
     */
    private function reservarConsecutivo(string $origen, string $destino, ?int $companyId, callable $formato): string
    {
        // La sede manda sobre el contexto: el consecutivo tiene que ser el de la
        // empresa dueña de la guía aunque quien la cree sea un proceso de fondo.
        $companyId ??= CompanyContext::id();

        return DB::transaction(function () use ($origen, $destino, $companyId, $formato) {
            $fila = DB::table('guide_sequences')
                ->where('company_id', $companyId)
                ->where('origin_prefix', $origen)
                ->where('destination_prefix', $destino)
                ->lockForUpdate()
                ->first();

            if (! $fila) {
                // insertOrIgnore y no insert: si otra transacción creó la fila
                // entre el select y esto, el índice único la rechaza en vez de
                // reventar, y se relee.
                DB::table('guide_sequences')->insertOrIgnore([
                    'company_id'         => $companyId,
                    'origin_prefix'      => $origen,
                    'destination_prefix' => $destino,
                    'last_number'        => 0,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                $fila = DB::table('guide_sequences')
                    ->where('company_id', $companyId)
                    ->where('origin_prefix', $origen)
                    ->where('destination_prefix', $destino)
                    ->lockForUpdate()
                    ->first();
            }

            $siguiente = (int) $fila->last_number + 1;

            // Si el contador quedó detrás de las guías que ya existen, el código
            // que toca ya está usado y el índice único tumba la creación: el
            // mostrador se queda sin poder recibir encomiendas en esa ruta. Se
            // salta al siguiente libre y el contador queda al día. Es una
            // consulta por índice y, con el contador sano, una sola.
            $saltados = 0;

            while (DB::table('invoices')
                ->where('company_id', $companyId)
                ->where('code', $formato($siguiente))
                ->exists()) {
                $siguiente++;
                $saltados++;
            }

            if ($saltados > 0) {
                Log::warning("Contador de guías atrasado en la ruta {$origen}-{$destino} (empresa {$companyId}): "
                    . "se saltaron {$saltados} códigos ya usados y se siguió en el {$siguiente}.");
            }

            DB::table('guide_sequences')
                ->where('id', $fila->id)
                ->update(['last_number' => $siguiente, 'updated_at' => now()]);

            return $formato($siguiente);
        });
    }
}
