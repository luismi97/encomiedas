<?php

namespace App\Livewire\Invoices;

use App\Livewire\Concerns\ScrollInfinito;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\GuideStatusService;
use RuntimeException;
use Illuminate\Support\Carbon;
use Livewire\Component;

class InvoiceIndex extends Component
{
    use ScrollInfinito;

    public string $period = 'today'; // today|week|month|range|all
    public string $from = '';
    public string $to = '';
    public string $status = '';
    public $branchId = null;
    public string $search = '';

    /** '' todas · 'domicilio' · 'sede' (retira en la sucursal). */
    public string $entrega = '';

    /** Ver Invoice::FILTROS_COBRO. */
    public string $cobro = '';

    /** Medio de pago: cash, card, sinpe... */
    public string $medio = '';

    /** Usuario que registró la guía. */
    public $creadaPor = null;

    public function mount(): void
    {
        $this->from = today()->toDateString();
        $this->to = today()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['period', 'from', 'to', 'status', 'branchId', 'search', 'entrega', 'cobro', 'medio', 'creadaPor'], true)) {
            $this->reiniciarScroll();
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
        $query = Invoice::query()->with(['pickupBranch', 'deliveryBranch', 'assignedTo', 'creator']);

        $user = auth()->user();
        if ($user->isRepartidor()) {
            $query->where('assigned_to', $user->id);
        }

        $query->filtrar($this->filtros());

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
        if (in_array($status, [Invoice::STATUS_DELIVERED, Invoice::STATUS_CANCELLED, Invoice::STATUS_RETURNED], true)) {
            session()->flash('error', 'Abrí la guía para ' . match ($status) {
                Invoice::STATUS_DELIVERED => 'registrar quién la retira.',
                Invoice::STATUS_RETURNED  => 'indicar el motivo de la devolución.',
                default                   => 'indicar el motivo de la anulación.',
            });

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

    /** Los filtros tal como los entiende Invoice::filtrar() y la exportación. */
    public function filtros(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'status' => $this->status,
            'branch_id' => $this->branchId, 'search' => $this->search, 'entrega' => $this->entrega,
            'cobro' => $this->cobro, 'medio' => $this->medio, 'creada_por' => $this->creadaPor,
        ];
    }

    public function limpiarFiltros(): void
    {
        $this->reset(['status', 'branchId', 'search', 'entrega', 'cobro', 'medio', 'creadaPor']);
        $this->reiniciarScroll();
    }

    public function render()
    {
        $tanda = $this->tanda($this->baseQuery());

        return view('livewire.invoices.invoice-index', [
            'invoices' => $tanda['items'],
            'scroll'   => $tanda,
            'branches' => Branch::orderBy('name')->get(),
            'statuses' => Invoice::STATUSES,
            // Quienes registran guías: también los inactivos, porque sus
            // guías siguen ahí y alguien puede necesitar revisarlas.
            'usuarios' => User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_CAJERO, User::ROLE_DEPENDIENTE])->orderBy('name')->get(['id', 'name', 'is_active']),
            'filtrosCobro' => Invoice::FILTROS_COBRO,
            'mediosDePago' => Invoice::PAYMENT_METHODS,
        ])->layout('layouts.app', ['title' => 'Facturas / Encomiendas']);
    }
}
