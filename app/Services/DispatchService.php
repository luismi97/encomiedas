<?php

namespace App\Services;

use App\Models\Dispatch;
use App\Models\DispatchGuide;
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
}
