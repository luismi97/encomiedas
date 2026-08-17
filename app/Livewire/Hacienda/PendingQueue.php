<?php

namespace App\Livewire\Hacienda;

use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use Livewire\Component;
use Livewire\WithPagination;

class PendingQueue extends Component
{
    use WithPagination;

    /** @var array<int> */
    public array $selected = [];
    public bool $selectAll = false;

    public string $tab = 'pending'; // pending|sent|rejected

    public function updatedSelectAll(bool $value): void
    {
        // Los que ya están en cola no se pueden volver a encolar: se excluyen
        // para que "seleccionar todos" no genere una lista de errores.
        $this->selected = $value
            ? $this->currentPageQuery()
                ->whereIn('status', [ElectronicInvoice::STATUS_PENDING, ElectronicInvoice::STATUS_ERROR])
                ->pluck('id')->toArray()
            : [];
    }

    public function updatedTab(): void
    {
        $this->selected = [];
        $this->selectAll = false;
        $this->resetPage();
    }

    private function currentPageQuery()
    {
        return $this->baseQuery()->get();
    }

    private function baseQuery()
    {
        $statusMap = [
            'pending'  => [ElectronicInvoice::STATUS_PENDING, ElectronicInvoice::STATUS_QUEUED, ElectronicInvoice::STATUS_ERROR],
            'sent'     => [ElectronicInvoice::STATUS_SENT],
            'rejected' => [ElectronicInvoice::STATUS_REJECTED],
            'accepted' => [ElectronicInvoice::STATUS_ACCEPTED],
        ];

        return ElectronicInvoice::with('invoice')
            ->whereIn('status', $statusMap[$this->tab] ?? $statusMap['pending'])
            ->latest();
    }

    public function sendSelected(ElectronicBillingService $service): void
    {
        if (empty($this->selected)) {
            session()->flash('error', 'Seleccione al menos un comprobante.');
            return;
        }

        $result = $service->sendBatch($this->selected);

        $this->selected = [];
        $this->selectAll = false;

        $message = count($result['queued']) . ' comprobante(s) en cola de envío a Hacienda.';
        if (!empty($result['errors'])) {
            $message .= ' ' . count($result['errors']) . ' no se pudieron encolar.';
        }
        session()->flash('success', $message);
    }

    public function sendOne(int $id, ElectronicBillingService $service): void
    {
        $electronicInvoice = ElectronicInvoice::findOrFail($id);
        try {
            $service->queueSend($electronicInvoice);
            session()->flash('success', 'Comprobante en cola de envío a Hacienda.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function retry(int $id, ElectronicBillingService $service): void
    {
        $electronicInvoice = ElectronicInvoice::findOrFail($id);
        try {
            $service->retry($electronicInvoice);
            session()->flash('success', 'Comprobante en cola para reintento.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.hacienda.pending-queue', [
            'items' => $this->baseQuery()->paginate(15),
        ])->layout('layouts.app', ['title' => 'Pendientes de envío a Hacienda']);
    }
}
