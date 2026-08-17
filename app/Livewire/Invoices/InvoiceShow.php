<?php

namespace App\Livewire\Invoices;

use App\Models\ActivityLog;
use App\Models\Invoice;
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

        $this->invoice = $invoice->load(['items', 'taxes', 'pickupBranch', 'deliveryBranch', 'creator', 'assignedTo', 'electronicInvoice', 'electronicNotes', 'activityLogs.user']);
    }

    public function updateStatus(string $status): void
    {
        $user = auth()->user();
        $oldStatus = $this->invoice->status;

        $this->invoice->status = $status;
        if ($status === Invoice::STATUS_DELIVERED) {
            $this->invoice->delivered_at = now();
        } elseif ($status === Invoice::STATUS_RETURNED) {
            $this->invoice->returned_at = now();
        }
        $this->invoice->save();

        ActivityLog::record(
            'status_changed',
            "{$user->name} cambió el estado de {$this->invoice->code} de \"" . Invoice::STATUSES[$oldStatus] . '" a "' . $this->invoice->statusLabel() . '".',
            $this->invoice,
            $oldStatus,
            $status,
        );

        $this->invoice->refresh()->load(['electronicInvoice', 'electronicNotes', 'activityLogs.user']);

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

    public function render()
    {
        return view('livewire.invoices.invoice-show')
            ->layout('layouts.app', ['title' => $this->invoice->code]);
    }
}
