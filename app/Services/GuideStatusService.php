<?php

namespace App\Services;

use App\Models\Branch;
use App\Notifications\CambioDeEstadoGuia;
use App\Models\GuideStatusHistory;
use App\Models\Invoice;
use App\Services\CajaService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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

        $guia = $guia->fresh();
        $this->avisarAlDestinatario($guia);

        return $guia;
    }

    /**
     * Anula una guía dejando constancia de quién y por qué.
     *
     * El motivo es obligatorio: una anulación sin explicación es exactamente lo
     * que después no se puede auditar.
     */
    public function anular(Invoice $guia, User $usuario, string $motivo): Invoice
    {
        if (trim($motivo) === '') {
            throw new RuntimeException('Toda anulación necesita un motivo.');
        }

        if (! $guia->sePuedeAnular()) {
            throw new RuntimeException(
                "La guía {$guia->code} está en «{$guia->statusLabel()}» y ya no se puede anular. "
                . 'Una encomienda que ya salió se devuelve, no se anula.'
            );
        }

        $guia->forceFill([
            'cancellation_reason' => trim($motivo),
            'cancelled_by'        => $usuario->id,
            'cancelled_at'        => now(),
        ])->save();

        return $this->cambiar(
            $guia,
            Invoice::STATUS_CANCELLED,
            $usuario,
            null,
            GuideStatusHistory::SOURCE_MANUAL,
            'Anulada: ' . trim($motivo)
        );
    }

    /**
     * Entrega con evidencia de quién retiró.
     *
     * La firma llega como data URI del canvas del navegador; se valida que sea
     * una imagen y no cualquier cadena, porque viene del cliente.
     */
    public function entregar(
        Invoice $guia,
        User $usuario,
        string $nombreQuienRetira,
        ?string $identificacion = null,
        ?string $firmaDataUri = null
    ): Invoice {
        if (trim($nombreQuienRetira) === '') {
            throw new RuntimeException('Hay que registrar el nombre de quien retira la encomienda.');
        }

        $firma = null;

        if ($firmaDataUri && preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $firmaDataUri)) {
            $firma = $firmaDataUri;
        }

        $guia->forceFill([
            'received_by_name'           => trim($nombreQuienRetira),
            'received_by_identification' => $identificacion ? preg_replace('/\D/', '', $identificacion) : null,
            'delivery_signature'         => $firma,
        ])->save();

        $entregada = $this->cambiar(
            $guia,
            Invoice::STATUS_DELIVERED,
            $usuario,
            null,
            GuideStatusHistory::SOURCE_MANUAL,
            'Retirada por ' . trim($nombreQuienRetira)
        );

        $this->cobrarSiEstabaPorCobrar($entregada, $usuario);

        return $entregada;
    }

    /**
     * El flete «por cobrar» se cobra al entregar, en la caja de destino.
     *
     * Esa plata nunca pasó por el mostrador de origen: registrarla allá habría
     * dejado el arqueo de origen con un ingreso que no estaba en la gaveta.
     */
    private function cobrarSiEstabaPorCobrar(Invoice $guia, User $usuario): void
    {
        if (! $guia->tieneCobroPendiente()) {
            return;
        }

        $sesion = app(CajaService::class)
            ->sesionAbiertaPara($usuario, $guia->delivery_branch_id);

        if (! $sesion) {
            Log::warning("Entrega de {$guia->code}: sin caja abierta en destino, el cobro por cobrar "
                . 'no quedó registrado en el arqueo.');

            return;
        }

        app(CajaService::class)->registrarCobro($guia, $usuario, $sesion);

        $guia->forceFill(['collected_at' => now()])->save();
    }

    /**
     * Avisa por correo cuando el cambio le pide algo al destinatario.
     *
     * Falla en silencio a propósito: un correo que no sale no puede impedir que
     * el paquete cambie de estado, porque el estado ya cambió físicamente.
     */
    private function avisarAlDestinatario(Invoice $guia): void
    {
        if (! CambioDeEstadoGuia::aplicaA($guia->status) || blank($guia->recipient_email)) {
            return;
        }

        try {
            Notification::route('mail', $guia->recipient_email)
                ->notify(new CambioDeEstadoGuia($guia));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar del estado de ' . $guia->code . ': ' . $e->getMessage());
        }
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
