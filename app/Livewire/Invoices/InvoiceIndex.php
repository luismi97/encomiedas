<?php

namespace App\Livewire\Invoices;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Invoice;
use App\Services\GuideStatusService;
use RuntimeException;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class InvoiceIndex extends Component
{
    use WithPagination;

    public string $period = 'today'; // today|week|month|range|all
    public string $from = '';
    public string $to = '';
    public string $status = '';
    public ?int $branchId = null;
    public string $search = '';

    public function mount(): void
    {
        $this->from = today()->toDateString();
        $this->to = today()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['period', 'from', 'to', 'status', 'branchId', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function updatedPeriod(): void
    {
        [$from, $to] = $this->rangeForPeriod($this->period);
        $this->from = $from?->toDateString() ?? '';
        $this->to = $to?->toDateString() ?? '';
    }

    private function rangeForPeriod(string $period): array
    {
        return match ($period) {
            'today' => [today(), today()],
            'week'  => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'all'   => [null, null],
            default => [
                $this->from ? Carbon::parse($this->from) : null,
                $this->to ? Carbon::parse($this->to) : null,
            ],
        };
    }

    public function baseQuery()
    {
        $query = Invoice::query()->with(['pickupBranch', 'deliveryBranch', 'assignedTo']);

        $user = auth()->user();
        if ($user->isRepartidor()) {
            $query->where('assigned_to', $user->id);
        }

        if ($this->from) {
            $query->whereDate('created_at', '>=', $this->from);
        }
        if ($this->to) {
            $query->whereDate('created_at', '<=', $this->to);
        }
        if ($this->status) {
            $query->where('status', $this->status);
        }
        if ($this->branchId) {
            $query->where(function ($q) {
                $q->where('pickup_branch_id', $this->branchId)
                    ->orWhere('delivery_branch_id', $this->branchId);
            });
        }
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('code', 'like', "%{$this->search}%")
                    ->orWhere('recipient_name', 'like', "%{$this->search}%")
                    ->orWhere('sender_name', 'like', "%{$this->search}%");
            });
        }

        return $query->latest();
    }

    public function updateStatus(int $invoiceId, string $status): void
    {
        $invoice = Invoice::findOrFail($invoiceId);

        $user = auth()->user();
        if ($user->isRepartidor() && $invoice->assigned_to !== $user->id) {
            session()->flash('error', 'Esta encomienda no está asignada a usted.');
            return;
        }

        // Entregar y anular piden datos extra (quién retiró, motivo): se
        // resuelven en la pantalla de la guía, no desde el listado.
        if (in_array($status, [Invoice::STATUS_DELIVERED, Invoice::STATUS_CANCELLED], true)) {
            session()->flash('error', 'Abrí la guía para ' .
                ($status === Invoice::STATUS_DELIVERED ? 'registrar quién la retira.' : 'indicar el motivo de la anulación.'));

            return;
        }

        $oldStatus = $invoice->status;

        // Pasa por el servicio para que valide la transición, selle las fechas
        // y deje la bitácora. Antes se asignaba el estado a mano y el listado
        // podía saltar pasos que la pantalla de detalle sí respetaba.
        try {
            $invoice = app(GuideStatusService::class)->cambiar($invoice, $status, $user);
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        ActivityLog::record(
            'status_changed',
            "{$user->name} cambió el estado de {$invoice->code} de \"" . Invoice::STATUSES[$oldStatus] . '" a "' . $invoice->statusLabel() . '".',
            $invoice,
            $oldStatus,
            $status,
        );

        session()->flash('success', 'Estado actualizado a "' . $invoice->statusLabel() . '".');
    }

    public function render()
    {
        return view('livewire.invoices.invoice-index', [
            'invoices' => $this->baseQuery()->paginate(12),
            'branches' => Branch::orderBy('name')->get(),
            'statuses' => Invoice::STATUSES,
        ])->layout('layouts.app', ['title' => 'Facturas / Encomiendas']);
    }
}
