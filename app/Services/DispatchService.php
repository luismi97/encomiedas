<?php

namespace App\Services;

use App\Models\Dispatch;
use App\Models\DispatchGuide;
use App\Models\GuideIncident;
use App\Models\GuideStatusHistory;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cierres de envío: armar el manifiesto, despacharlo y recibirlo en destino.
 *
 * Cada operación arrastra el estado de las guías incluidas, y eso es lo que
 * justifica que exista el servicio: si cada pantalla moviera los estados por su
 * cuenta, tarde o temprano una se olvidaría de alguna.
 */
class DispatchService
{
    public function __construct(private GuideStatusService $estados)
    {
    }

    /** Guías que pueden entrar a un manifiesto de esta ruta. */
    public function disponiblesPara(Dispatch $manifiesto)
    {
        return Invoice::query()
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_READY])
            ->where('pickup_branch_id', $manifiesto->origin_branch_id)
            ->where('delivery_branch_id', $manifiesto->destination_branch_id)
            // Una guía no puede ir en dos manifiestos abiertos a la vez.
            ->whereDoesntHave('dispatchLines', fn ($q) => $q->whereHas('dispatch',
                fn ($d) => $d->whereIn('status', [Dispatch::STATUS_OPEN, Dispatch::STATUS_DISPATCHED])))
            ->with('items')
            ->orderBy('created_at')
            ->get();
    }

    public function agregarGuia(Dispatch $manifiesto, Invoice $guia): void
    {
        if (! $manifiesto->estaAbierto()) {
            throw new RuntimeException('El cierre ya salió: no se le pueden agregar guías.');
        }

        if ($guia->pickup_branch_id !== $manifiesto->origin_branch_id
            || $guia->delivery_branch_id !== $manifiesto->destination_branch_id) {
            throw new RuntimeException("La guía {$guia->code} es de otra ruta y no puede ir en este cierre.");
        }

        DispatchGuide::firstOrCreate([
            'dispatch_id' => $manifiesto->id,
            'invoice_id'  => $guia->id,
        ]);
    }

    public function quitarGuia(Dispatch $manifiesto, Invoice $guia): void
    {
        if (! $manifiesto->estaAbierto()) {
            throw new RuntimeException('El cierre ya salió: no se le pueden quitar guías.');
        }

        DispatchGuide::where('dispatch_id', $manifiesto->id)->where('invoice_id', $guia->id)->delete();
    }

    /**
     * Cierra el manifiesto: el camión sale y todas sus guías pasan a "Enviado".
     */
    public function despachar(Dispatch $manifiesto, User $usuario): Dispatch
    {
        if (! $manifiesto->estaAbierto()) {
            throw new RuntimeException('Este cierre ya fue despachado.');
        }

        $manifiesto->loadMissing('guides');

        if ($manifiesto->guides->isEmpty()) {
            throw new RuntimeException('El cierre no tiene guías: no hay nada que despachar.');
        }

        DB::transaction(function () use ($manifiesto, $usuario) {
            foreach ($manifiesto->guides as $guia) {
                // Una guía recién recibida todavía no está "lista": se la pasa
                // por el paso intermedio para no romper el ciclo de estados.
                if ($guia->status === Invoice::STATUS_PENDING) {
                    $guia = $this->estados->cambiar($guia, Invoice::STATUS_READY, $usuario,
                        $manifiesto->originBranch, GuideStatusHistory::SOURCE_SYSTEM,
                        "Incluida en el cierre {$manifiesto->code}.");
                }

                $this->estados->cambiar($guia, Invoice::STATUS_DISPATCHED, $usuario,
                    $manifiesto->originBranch, GuideStatusHistory::SOURCE_MANUAL,
                    "Salió en el cierre {$manifiesto->code}.");
            }

            $manifiesto->update([
                'status'        => Dispatch::STATUS_DISPATCHED,
                'departed_at'   => now(),
                'dispatched_by' => $usuario->id,
            ]);
        });

        return $manifiesto->fresh();
    }

    /** Marca una guía como recibida en la sede destino. */
    public function recibirGuia(Dispatch $manifiesto, Invoice $guia, User $usuario, string $source = GuideStatusHistory::SOURCE_MANUAL): void
    {
        if (! $manifiesto->enRuta()) {
            throw new RuntimeException('Solo se pueden recibir guías de un cierre que está en ruta.');
        }

        $linea = DispatchGuide::where('dispatch_id', $manifiesto->id)
            ->where('invoice_id', $guia->id)
            ->first();

        if (! $linea) {
            throw new RuntimeException("La guía {$guia->code} no viene en este cierre.");
        }

        if ($linea->fueRecibida()) {
            return;
        }

        DB::transaction(function () use ($linea, $guia, $manifiesto, $usuario, $source) {
            $linea->update(['received_at' => now(), 'received_by' => $usuario->id]);

            $this->estados->cambiar($guia, Invoice::STATUS_AT_DESTINATION, $usuario,
                $manifiesto->destinationBranch, $source,
                "Recibida en destino con el cierre {$manifiesto->code}.");
        });
    }

    /**
     * Cierra la recepción. Lo que no se marcó queda registrado como faltante:
     * la diferencia entre lo despachado y lo recibido es el control que importa.
     *
     * Cada faltante abre además una incidencia de extravío. Marcar la línea no
     * alcanzaba: la marca quedaba dentro de un manifiesto ya cerrado, que nadie
     * vuelve a abrir, y el paquete perdido desaparecía de la vista de todos. Como
     * incidencia aparece en la guía, entra en el trabajo pendiente y se puede dar
     * por resuelta el día que aparezca.
     */
    public function cerrarRecepcion(Dispatch $manifiesto, User $usuario): array
    {
        if (! $manifiesto->enRuta()) {
            throw new RuntimeException('Este cierre no está en ruta.');
        }

        $manifiesto->loadMissing('lines.invoice');
        $faltantes = $manifiesto->faltantes();

        DB::transaction(function () use ($manifiesto, $usuario, $faltantes) {
            foreach ($faltantes as $linea) {
                $linea->update(['incident' => 'faltante']);
                $this->abrirIncidenciaDeExtravio($manifiesto, $linea, $usuario);
            }

            $manifiesto->update([
                'status'      => Dispatch::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by' => $usuario->id,
            ]);
        });

        return [
            'recibidas' => $manifiesto->recibidas()->count(),
            'faltantes' => $faltantes->pluck('invoice.code')->filter()->values()->all(),
        ];
    }

    /**
     * El faltante apareció: se recibe contra el cierre ya cerrado.
     *
     * Un manifiesto cerrado no admite recepciones normales, y está bien que sea
     * así: su conteo es el control del viaje y no se reescribe a posteriori. Pero
     * el paquete aparece —se traspapeló en bodega, viajó en el camión siguiente—,
     * y sin esta salida la guía se quedaba en «Enviado» para siempre: tampoco
     * podía entrar en otro cierre, porque solo se ofrecen las que están en origen.
     *
     * La línea conserva la marca de faltante a propósito. Lo que se corrige es
     * dónde está la encomienda hoy, no lo que pasó en aquel viaje.
     *
     * @return bool false si ya la habían recuperado: repetirlo no es un error,
     *              pero decirle al operario que acaba de recibirla sí lo sería.
     */
    public function recibirFaltante(Dispatch $manifiesto, Invoice $guia, User $usuario, ?string $nota = null): bool
    {
        $linea = DispatchGuide::where('dispatch_id', $manifiesto->id)
            ->where('invoice_id', $guia->id)
            ->first();

        if (! $linea || $linea->incident !== 'faltante') {
            throw new RuntimeException("La guía {$guia->code} no quedó como faltante en este cierre.");
        }

        if ($linea->fueRecibida()) {
            return false;
        }

        DB::transaction(function () use ($linea, $guia, $manifiesto, $usuario, $nota) {
            $linea->update(['received_at' => now(), 'received_by' => $usuario->id]);

            $this->estados->cambiar($guia, Invoice::STATUS_AT_DESTINATION, $usuario,
                $manifiesto->destinationBranch, GuideStatusHistory::SOURCE_MANUAL,
                $nota ?: "Apareció después de cerrada la recepción del cierre {$manifiesto->code}.");

            $this->cerrarIncidenciasDeExtravio($guia, $usuario);
        });

        return true;
    }

    /** Una incidencia de extravío por cada guía que no llegó. */
    private function abrirIncidenciaDeExtravio(Dispatch $manifiesto, DispatchGuide $linea, User $usuario): void
    {
        if (! $linea->invoice) {
            return;
        }

        $salida = $manifiesto->departed_at
            ? ', despachado el ' . $manifiesto->departed_at->format('d/m/Y H:i')
            : '';

        GuideIncident::create([
            'invoice_id'  => $linea->invoice_id,
            'type'        => GuideIncident::TYPE_LOST,
            'description' => "No llegó en el cierre {$manifiesto->code} ({$manifiesto->rutaLabel()}){$salida}. "
                . 'La guía sigue en «Enviado» hasta que aparezca.',
            'branch_id'   => $manifiesto->destination_branch_id,
            'reported_by' => $usuario->id,
            'reported_at' => now(),
        ]);
    }

    /** Cierra el extravío que abrió la recepción, no las incidencias ajenas. */
    private function cerrarIncidenciasDeExtravio(Invoice $guia, User $usuario): void
    {
        GuideIncident::where('invoice_id', $guia->id)
            ->where('type', GuideIncident::TYPE_LOST)
            ->whereNull('resolved_at')
            ->update([
                'resolution'  => 'La guía apareció y se recibió en la sede destino.',
                'resolved_by' => $usuario->id,
                'resolved_at' => now(),
            ]);
    }
}
