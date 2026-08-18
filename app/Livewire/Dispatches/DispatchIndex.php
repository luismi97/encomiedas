<?php

namespace App\Livewire\Dispatches;

use App\Models\Branch;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class DispatchIndex extends Component
{
    use WithPagination;

    public string $filterStatus = '';

    public bool $showForm = false;
    public ?int $origin_branch_id = null;
    public ?int $destination_branch_id = null;
    public string $driver_name = '';
    public ?int $driver_user_id = null;
    public string $vehicle_plate = '';
    public string $notes = '';

    /** Manifiesto abierto en el panel de detalle. */
    public ?int $openId = null;
    public string $scanCode = '';

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

    protected function rules(): array
    {
        return [
            'origin_branch_id' => 'required|exists:branches,id',
            'destination_branch_id' => 'required|exists:branches,id|different:origin_branch_id',
            'driver_name' => 'nullable|string|max:150',
            'driver_user_id' => 'nullable|exists:users,id',
            'vehicle_plate' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    protected function messages(): array
    {
        return [
            'destination_branch_id.different' => 'El destino tiene que ser una sede distinta del origen.',
            'origin_branch_id.required' => 'Elegí la sede de origen.',
            'destination_branch_id.required' => 'Elegí la sede de destino.',
        ];
    }

    public function create(): void
    {
        $this->feedback = null;
        $this->reset(['origin_branch_id', 'destination_branch_id', 'driver_name', 'driver_user_id', 'vehicle_plate', 'notes']);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        $manifiesto = Dispatch::create($data + [
            'code' => $this->siguienteCodigo(),
            'created_by' => auth()->id(),
        ]);

        $this->showForm = false;
        $this->openId = $manifiesto->id;
        $this->notify('success', "Cierre {$manifiesto->code} creado. Agregale las guías que salen en este viaje.");
    }

    /** CIE-000001, con reserva bajo candado para no repetirlo. */
    private function siguienteCodigo(): string
    {
        return DB::transaction(function () {
            $ultimo = Dispatch::lockForUpdate()->max('id') ?? 0;

            return 'CIE-' . str_pad((string) ($ultimo + 1), 6, '0', STR_PAD_LEFT);
        });
    }

    public function open(int $id): void
    {
        $this->feedback = null;
        $this->openId = $id;
        $this->scanCode = '';
    }

    public function close(): void
    {
        $this->openId = null;
    }

    public function agregar(int $invoiceId, DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $servicio->agregarGuia($this->manifiesto(), Invoice::findOrFail($invoiceId));
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }
    }

    public function quitar(int $invoiceId, DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $servicio->quitarGuia($this->manifiesto(), Invoice::findOrFail($invoiceId));
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }
    }

    public function despachar(DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $manifiesto = $servicio->despachar($this->manifiesto(), auth()->user());
            $this->notify('success', "Cierre {$manifiesto->code} despachado. Sus guías quedaron en «Enviado».");
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }
    }

    /** Recibe por código de guía: es lo que escribe el lector de QR. */
    public function recibirPorCodigo(DispatchService $servicio): void
    {
        $this->feedback = null;
        $codigo = trim($this->scanCode);

        if ($codigo === '') {
            return;
        }

        $guia = Invoice::where('code', $codigo)->first();

        if (! $guia) {
            $this->notify('error', "No existe ninguna guía con el código «{$codigo}».");
            $this->scanCode = '';

            return;
        }

        try {
            $servicio->recibirGuia($this->manifiesto(), $guia, auth()->user(), 'scan');
            $this->notify('success', "Guía {$guia->code} recibida.");
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }

        $this->scanCode = '';
    }

    public function recibir(int $invoiceId, DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $servicio->recibirGuia($this->manifiesto(), Invoice::findOrFail($invoiceId), auth()->user());
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }
    }

    public function cerrarRecepcion(DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $resumen = $servicio->cerrarRecepcion($this->manifiesto(), auth()->user());
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());

            return;
        }

        if ($resumen['faltantes']) {
            $this->notify('error', 'Recepción cerrada con ' . count($resumen['faltantes'])
                . ' faltante(s): ' . implode(', ', $resumen['faltantes'])
                . '. Quedaron registrados en el cierre.');

            return;
        }

        $this->notify('success', "Recepción cerrada: llegaron las {$resumen['recibidas']} guías del cierre, sin faltantes.");
    }

    private function manifiesto(): Dispatch
    {
        return Dispatch::with(['lines.invoice', 'guides.items', 'originBranch', 'destinationBranch'])
            ->findOrFail($this->openId);
    }

    public function render(DispatchService $servicio)
    {
        $abierto = $this->openId ? $this->manifiesto() : null;

        return view('livewire.dispatches.dispatch-index', [
            'abierto'     => $abierto,
            'disponibles' => $abierto?->estaAbierto() ? $servicio->disponiblesPara($abierto) : collect(),
            'dispatches'  => Dispatch::with(['originBranch', 'destinationBranch'])
                ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
                ->withCount('lines')
                ->latest()
                ->paginate(10),
            'branches'    => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name', 'prefix']),
            'choferes'    => User::where('role', User::ROLE_REPARTIDOR)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['title' => 'Cierres de envío']);
    }
}
