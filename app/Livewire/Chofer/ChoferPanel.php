<?php

namespace App\Livewire\Chofer;

use App\Models\Dispatch;
use App\Models\GuideIncident;
use App\Models\Invoice;
use App\Services\DispatchService;
use App\Services\GuideStatusService;
use Livewire\Component;
use RuntimeException;

/**
 * Vista para el chofer, pensada para un celular en la calle.
 *
 * Muestra solo el cierre que trae asignado y las guías que lleva encima. Nada
 * de configuración, listados generales ni montos: un chofer necesita escanear,
 * marcar y seguir manejando.
 */
class ChoferPanel extends Component
{
    public ?int $dispatchId = null;

    /** Lo que escribe el lector de QR. */
    public string $scanCode = '';

    /** Entrega */
    public ?int $deliveringId = null;
    public string $receivedByName = '';
    public string $receivedByIdentification = '';
    public string $deliverySignature = '';

    /** Incidencia */
    public ?int $incidentInvoiceId = null;
    public string $incidentType = GuideIncident::TYPE_ABSENT;
    public string $incidentDescription = '';

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    private function notify(string $type, string $message): void
    {
        $this->feedbackType = $type;
        $this->feedback = $message;
    }

    public function dismissFeedback(): void
    {
        $this->feedback = null;
    }

    /** Cierres asignados a este chofer que todavía están en ruta. */
    private function misCierres()
    {
        return Dispatch::withoutGlobalScopes()
            ->where('driver_user_id', auth()->id())
            ->where('status', Dispatch::STATUS_DISPATCHED)
            ->with(['originBranch', 'destinationBranch'])
            ->latest('departed_at')
            ->get();
    }

    private function cierre(): ?Dispatch
    {
        return $this->dispatchId
            ? Dispatch::withoutGlobalScopes()
                ->with(['lines.invoice.items', 'originBranch', 'destinationBranch'])
                ->find($this->dispatchId)
            : null;
    }

    public function abrirCierre(int $id): void
    {
        $this->feedback = null;
        $this->dispatchId = $id;
    }

    /** Escaneo: marca la guía como llegada a destino. */
    public function escanear(DispatchService $despachos): void
    {
        $this->feedback = null;
        $codigo = trim($this->scanCode);

        if ($codigo === '' || ! $cierre = $this->cierre()) {
            return;
        }

        $guia = Invoice::withoutGlobalScopes()->where('code', $codigo)->first();

        if (! $guia) {
            $this->notify('error', "No existe ninguna guía con el código «{$codigo}».");
            $this->scanCode = '';

            return;
        }

        try {
            $despachos->recibirGuia($cierre, $guia, auth()->user(), 'scan');
            $this->notify('success', "Guía {$guia->code} marcada como llegada.");
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }

        $this->scanCode = '';
    }

    public function abrirEntrega(int $invoiceId): void
    {
        $guia = Invoice::withoutGlobalScopes()->find($invoiceId);

        $this->deliveringId = $invoiceId;
        $this->receivedByName = (string) $guia?->recipient_name;
        $this->receivedByIdentification = (string) $guia?->recipient_identification;
        $this->deliverySignature = '';
    }

    public function entregar(GuideStatusService $estados): void
    {
        $this->feedback = null;
        $guia = Invoice::withoutGlobalScopes()->find($this->deliveringId);

        if (! $guia) {
            return;
        }

        try {
            $estados->entregar(
                $guia, auth()->user(),
                $this->receivedByName,
                $this->receivedByIdentification ?: null,
                $this->deliverySignature ?: null
            );
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());

            return;
        }

        $this->deliveringId = null;
        $this->notify('success', "Entregada a {$this->receivedByName}.");
    }

    public function abrirIncidencia(int $invoiceId): void
    {
        $this->incidentInvoiceId = $invoiceId;
        $this->incidentType = GuideIncident::TYPE_ABSENT;
        $this->incidentDescription = '';
    }

    public function registrarIncidencia(): void
    {
        $this->feedback = null;
        $descripcion = trim($this->incidentDescription);

        if ($descripcion === '' || ! $this->incidentInvoiceId) {
            $this->notify('error', 'Describí qué pasó.');

            return;
        }

        GuideIncident::create([
            'invoice_id'  => $this->incidentInvoiceId,
            'type'        => $this->incidentType,
            'description' => $descripcion,
            'branch_id'   => auth()->user()->branch_id,
            'reported_by' => auth()->id(),
            'reported_at' => now(),
        ]);

        $this->incidentInvoiceId = null;
        $this->notify('success', 'Incidencia registrada. La guía sigue en su estado actual.');
    }

    public function render()
    {
        return view('livewire.chofer.chofer-panel', [
            'cierres' => $this->misCierres(),
            'cierre'  => $this->cierre(),
        ])->layout('layouts.app', ['title' => 'Mi ruta']);
    }
}
