<?php

namespace App\Livewire\Invoices;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Services\Hacienda\ElectronicBillingService;
use Livewire\Component;

class InvoiceShow extends Component
{
    public Invoice $invoice;

    public function mount(Invoice $invoice): void
    {
        $user = auth()->user();
        if ($user->isRepartidor() && $invoice->assigned_to !== $user->id) {
            abort(403, 'Esta encomienda no está asignada a usted.');
        }

        $this->invoice = $invoice->load(['items', 'taxes', 'pickupBranch', 'deliveryBranch', 'creator', 'assignedTo', 'electronicInvoice', 'activityLogs.user']);
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

        $this->invoice->refresh()->load(['electronicInvoice', 'activityLogs.user']);

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
            $service->send($electronicInvoice);
            $this->invoice->refresh()->load(['electronicInvoice', 'activityLogs.user']);
            ActivityLog::record(
                'hacienda_sent',
                auth()->user()->name . " envió el comprobante de {$this->invoice->code} a Hacienda.",
                $this->invoice,
            );
            session()->flash('success', 'Comprobante enviado a Hacienda.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.invoices.invoice-show')
            ->layout('layouts.app', ['title' => $this->invoice->code]);
    }
}
