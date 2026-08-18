<?php

namespace App\Livewire\Invoices;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Services\GuideStatusService;
use App\Services\QrService;
use RuntimeException;
use App\Services\Hacienda\ElectronicBillingService;
use Livewire\Component;

class InvoiceShow extends Component
{
    public Invoice $invoice;

    // Formulario de nota de crédito / débito
    public bool $showNoteForm = false;
    public string $noteType = 'NC';
    public string $noteReason = '';
    public ?float $noteAmount = null;

    public function mount(Invoice $invoice): void
    {
        $user = auth()->user();
        if ($user->isRepartidor() && $invoice->assigned_to !== $user->id) {
            abort(403, 'Esta encomienda no está asignada a usted.');
        }

        $this->invoice = $invoice->load([
            'items', 'taxes', 'pickupBranch', 'deliveryBranch', 'creator', 'assignedTo',
            'electronicInvoice', 'electronicNotes', 'activityLogs.user',
            'senderCustomer', 'recipientCustomer',
            'statusHistories.user', 'statusHistories.branch',
            'incidents.reporter', 'incidents.resolver',
        ]);
    }

    /**
     * Todo cambio pasa por GuideStatusService: es quien valida la transición,
     * sella las fechas y deja la bitácora. Antes se asignaba el estado a mano y
     * cada pantalla tenía que acordarse de las tres cosas.
     */
    /** Anulación */
    public bool $showCancelForm = false;
    public string $cancelReason = '';

    /** Entrega con evidencia */
    public bool $showDeliveryForm = false;
    public string $receivedByName = '';
    public string $receivedByIdentification = '';
    public string $deliverySignature = '';

    /** Incidencias */
    public bool $showIncidentForm = false;
    public string $incidentType = \App\Models\GuideIncident::TYPE_ABSENT;
    public string $incidentDescription = '';

    public function openIncidentForm(): void
    {
        $this->incidentType = \App\Models\GuideIncident::TYPE_ABSENT;
        $this->incidentDescription = '';
        $this->showIncidentForm = true;
    }

    public function registrarIncidencia(): void
    {
        $descripcion = trim($this->incidentDescription);

        if ($descripcion === '') {
            session()->flash('error', 'Describí qué pasó: una incidencia sin detalle no sirve para dar seguimiento.');

            return;
        }

        \App\Models\GuideIncident::create([
            'invoice_id'  => $this->invoice->id,
            'type'        => $this->incidentType,
            'description' => $descripcion,
            'branch_id'   => auth()->user()->branch_id,
            'reported_by' => auth()->id(),
            'reported_at' => now(),
        ]);

        $this->showIncidentForm = false;
        $this->invoice->load('incidents.reporter', 'incidents.resolver');
        session()->flash('success', 'Incidencia registrada. La guía sigue en su estado actual.');
    }

    public function resolverIncidencia(int $id): void
    {
        $incidencia = \App\Models\GuideIncident::where('invoice_id', $this->invoice->id)->find($id);

        if (! $incidencia || $incidencia->estaResuelta()) {
            return;
        }

        $incidencia->update([
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
            'resolution'  => 'Resuelta por ' . auth()->user()->name,
        ]);

        $this->invoice->load('incidents.reporter', 'incidents.resolver');
        session()->flash('success', 'Incidencia marcada como resuelta.');
    }

    public function openCancelForm(): void
    {
        $this->cancelReason = '';
        $this->showCancelForm = true;
    }

    public function openDeliveryForm(): void
    {
        // Precarga el nombre del destinatario: la mayoría de las veces retira él.
        $this->receivedByName = (string) $this->invoice->recipient_name;
        $this->receivedByIdentification = (string) $this->invoice->recipient_identification;
        $this->deliverySignature = '';
        $this->showDeliveryForm = true;
    }

    public function anular(GuideStatusService $estados): void
    {
        try {
            $this->invoice = $estados->anular($this->invoice, auth()->user(), $this->cancelReason);
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->showCancelForm = false;
        $this->invoice->load(['statusHistories.user', 'statusHistories.branch', 'canceller']);
        session()->flash('success', 'Guía anulada. El motivo quedó en la bitácora.');
    }

    public function entregar(GuideStatusService $estados): void
    {
        try {
            $this->invoice = $estados->entregar(
                $this->invoice,
                auth()->user(),
                $this->receivedByName,
                $this->receivedByIdentification ?: null,
                $this->deliverySignature ?: null
            );
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->showDeliveryForm = false;
        $this->invoice->load(['statusHistories.user', 'statusHistories.branch', 'electronicInvoice']);
        session()->flash('success', 'Entrega registrada a nombre de ' . $this->invoice->received_by_name . '.');
    }

    public function updateStatus(string $status, GuideStatusService $estados): void
    {
        // Estos dos exigen datos extra y tienen su propio formulario.
        if ($status === Invoice::STATUS_DELIVERED) {
            $this->openDeliveryForm();

            return;
        }

        if ($status === Invoice::STATUS_CANCELLED) {
            $this->openCancelForm();

            return;
        }

        $user = auth()->user();
        $anterior = $this->invoice->status;

        try {
            $this->invoice = $estados->cambiar($this->invoice, $status, $user);
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        ActivityLog::record(
            'status_changed',
            "{$user->name} cambió el estado de {$this->invoice->code} de \"" . Invoice::STATUSES[$anterior] . '" a "' . $this->invoice->statusLabel() . '".',
            $this->invoice,
            $anterior,
            $status,
        );

        $this->invoice->load([
            'electronicInvoice', 'electronicNotes', 'activityLogs.user',
            'statusHistories.user', 'statusHistories.branch',
        ]);

        session()->flash('success', 'Estado actualizado a "' . $this->invoice->statusLabel() . '".');
    }

    public function sendToHacienda(ElectronicBillingService $service): void
    {
        $electronicInvoice = $this->invoice->electronicInvoice;
        if (!$electronicInvoice) {
            session()->flash('error', 'Esta factura todavía no tiene un comprobante en espera de envío.');
            return;
        }

        try {
            $service->queueSend($electronicInvoice);
            $this->reloadInvoice();
            ActivityLog::record(
                'hacienda_sent',
                auth()->user()->name . " envió el comprobante de {$this->invoice->code} a Hacienda.",
                $this->invoice,
            );
            session()->flash('success', 'Comprobante en cola de envío a Hacienda.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openNoteForm(string $type): void
    {
        $this->noteType = in_array($type, ['NC', 'ND'], true) ? $type : 'NC';
        $this->noteReason = '';
        $this->noteAmount = (float) ($this->invoice->electronicInvoice?->total ?? 0);
        $this->showNoteForm = true;
    }

    public function closeNoteForm(): void
    {
        $this->showNoteForm = false;
        $this->resetValidation();
    }

    /**
     * Emite una nota de crédito o débito contra el comprobante aceptado. No
     * existe "anular" una factura ante Hacienda: se corrige con una nota.
     */
    public function issueNote(ElectronicBillingService $service): void
    {
        $this->validate([
            'noteType'   => 'required|in:NC,ND',
            'noteReason' => 'required|string|min:5|max:180',
            'noteAmount' => 'required|numeric|min:0.01',
        ], [], [
            'noteReason' => 'razón',
            'noteAmount' => 'monto',
        ]);

        $original = $this->invoice->electronicInvoice;

        if (!$original) {
            session()->flash('error', 'Esta factura no tiene comprobante electrónico.');
            return;
        }

        try {
            $note = $service->issueNote($original, $this->noteType, $this->noteReason, $this->noteAmount);

            ActivityLog::record(
                'hacienda_note_issued',
                auth()->user()->name . ' emitió una ' . $note->typeLabel() . " sobre {$this->invoice->code}: {$this->noteReason}.",
                $this->invoice,
            );

            $this->showNoteForm = false;
            $this->reloadInvoice();
            session()->flash('success', $note->typeLabel() . ' ' . $note->consecutivo . ' emitida y en cola de envío.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function reloadInvoice(): void
    {
        $this->invoice->refresh()->load(['electronicInvoice', 'electronicNotes', 'activityLogs.user']);
    }

    public function render(QrService $qr)
    {
        return view('livewire.invoices.invoice-show', ['qrSvg' => $qr->svg($this->invoice->trackingUrl(), 150)])
            ->layout('layouts.app', ['title' => $this->invoice->code]);
    }
}
