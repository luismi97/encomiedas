<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GuideStatusHistory;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Único punto por donde una guía cambia de estado.
 *
 * Concentra tres cosas que estaban sueltas: validar que la transición sea legal,
 * dejar la bitácora, y poner las marcas de tiempo que después usan el cron de
 * desecho y la facturación electrónica.
 */
class GuideStatusService
{
    /**
     * @param  string  $source  manual | scan | system
     *
     * @throws RuntimeException si la transición no está permitida
     */
    public function cambiar(
        Invoice $guia,
        string $nuevoEstado,
        ?User $usuario = null,
        ?Branch $sede = null,
        string $source = GuideStatusHistory::SOURCE_MANUAL,
        ?string $nota = null
    ): Invoice {
        if ($guia->status === $nuevoEstado) {
            return $guia;
        }

        if (! $guia->puedePasarA($nuevoEstado)) {
            throw new RuntimeException($this->explicarRechazo($guia, $nuevoEstado));
        }

        $anterior = $guia->status;

        DB::transaction(function () use ($guia, $nuevoEstado, $anterior, $usuario, $sede, $source, $nota) {
            $guia->status = $nuevoEstado;
            $this->sellarTiempos($guia, $nuevoEstado);
            $guia->save();

            GuideStatusHistory::create([
                'invoice_id'  => $guia->id,
                'from_status' => $anterior,
                'to_status'   => $nuevoEstado,
                'branch_id'   => $sede?->id ?? $usuario?->branch_id,
                'user_id'     => $usuario?->id,
                'source'      => $source,
                'note'        => $nota,
                'happened_at' => now(),
            ]);
        });

        return $guia->fresh();
    }

    /**
     * Marcas de tiempo del estado. arrived_at es la que más pesa: de ella se
     * cuentan los días para próximo-a-desecho.
     */
    private function sellarTiempos(Invoice $guia, string $estado): void
    {
        match ($estado) {
            Invoice::STATUS_AT_DESTINATION => $guia->arrived_at = $guia->arrived_at ?? now(),
            Invoice::STATUS_DELIVERED      => $guia->delivered_at = $guia->delivered_at ?? now(),
            Invoice::STATUS_RETURNED       => $guia->returned_at = $guia->returned_at ?? now(),
            Invoice::STATUS_NEAR_DISPOSAL  => $guia->disposal_warned_at = $guia->disposal_warned_at ?? now(),
            Invoice::STATUS_DISPOSED       => $guia->disposed_at = $guia->disposed_at ?? now(),
            default                        => null,
        };
    }

    /** Deja la primera fila de la bitácora al crear la guía. */
    public function registrarCreacion(Invoice $guia, ?User $usuario = null): void
    {
        GuideStatusHistory::create([
            'invoice_id'  => $guia->id,
            'from_status' => null,
            'to_status'   => $guia->status,
            'branch_id'   => $guia->pickup_branch_id,
            'user_id'     => $usuario?->id ?? $guia->created_by,
            'source'      => GuideStatusHistory::SOURCE_MANUAL,
            'note'        => 'Encomienda recibida en sede origen.',
            'happened_at' => now(),
        ]);
    }

    /** Un mensaje que diga qué se puede hacer, no solo que no se pudo. */
    private function explicarRechazo(Invoice $guia, string $nuevoEstado): string
    {
        $actual  = Invoice::STATUSES[$guia->status] ?? $guia->status;
        $destino = Invoice::STATUSES[$nuevoEstado] ?? $nuevoEstado;

        if ($guia->estaCerrada()) {
            return "La guía {$guia->code} está en «{$actual}», que es un estado final: ya no admite más cambios.";
        }

        $posibles = implode(', ', $guia->siguientesEstados());

        return "La guía {$guia->code} no puede pasar de «{$actual}» a «{$destino}». Desde aquí solo puede ir a: {$posibles}.";
    }
}
