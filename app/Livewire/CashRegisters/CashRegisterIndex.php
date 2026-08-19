<?php

namespace App\Livewire\CashRegisters;

use App\Models\Branch;
use App\Models\CashRegister;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Administración de las cajas de cada sede.
 *
 * Una sede puede tener varias («Mostrador 1», «Mostrador 2») y cada una lleva
 * su propio turno y su propio arqueo: dos cajeros cobrando a la vez en la misma
 * sede no pueden compartir gaveta, o el faltante de uno aparece en el conteo
 * del otro.
 */
class CashRegisterIndex extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;

    public ?int $branch_id = null;
    public string $name = '';
    public bool $is_active = true;

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    protected function rules(): array
    {
        return [
            'branch_id' => 'required|exists:branches,id',
            // Único dentro de la sede: con dos «Mostrador 1» en San José el
            // cajero no sabe en cuál está abriendo el turno.
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('cash_registers', 'name')
                    ->where(fn ($q) => $q->where('branch_id', $this->branch_id))
                    ->ignore($this->editingId),
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'branch_id.required' => 'Elegí a qué sede pertenece la caja.',
            'name.required' => 'Ponele un nombre a la caja, por ejemplo «Mostrador 2».',
            'name.unique' => 'Esa sede ya tiene una caja con ese nombre.',
        ];
    }

    private function notify(string $type, string $message): void
    {
        $this->feedbackType = $type;
        $this->feedback = $message;
    }

    public function dismissFeedback(): void
    {
        $this->feedback = null;
    }

    public function create(): void
    {
        $this->feedback = null;
        $this->resetForm();
        $this->showForm = true;
    }

    /** Sugiere «Mostrador N» según cuántas cajas tenga ya la sede. */
    public function updatedBranchId($value): void
    {
        if ($this->editingId || ! $value) {
            return;
        }

        $this->name = 'Mostrador ' . (CashRegister::where('branch_id', $value)->count() + 1);
    }

    public function edit(int $id): void
    {
        $this->feedback = null;

        if (! $caja = CashRegister::find($id)) {
            $this->notify('error', 'La caja que intentás editar ya no existe.');

            return;
        }

        $this->resetErrorBag();
        $this->editingId = $caja->id;
        $this->branch_id = $caja->branch_id;
        $this->name = (string) $caja->name;
        $this->is_active = (bool) $caja->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->feedback = null;

        // Antes de validar: si la caja no se puede mover, el motivo es su
        // historial, no que la sede destino ya tenga una caja con ese nombre.
        // Validando primero ganaría el mensaje equivocado.
        if ($this->editingId) {
            $caja = CashRegister::find($this->editingId);

            if ($caja && $caja->branch_id !== (int) $this->branch_id && $caja->tieneHistorial()) {
                $this->notify('error', "«{$caja->name}» ya tiene turnos registrados y no se puede cambiar de sede: "
                    . 'sus arqueos quedarían contabilizados donde no ocurrieron.');

                return;
            }
        }

        $data = $this->validate();

        try {
            CashRegister::updateOrCreate(
                ['id' => $this->editingId],
                $data + ['is_active' => $this->is_active]
            );
        } catch (QueryException $e) {
            report($e);
            $this->notify('error', 'No se pudo guardar la caja.');

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->notify('success', 'Caja guardada correctamente.');
    }

    public function toggleActive(int $id): void
    {
        $this->feedback = null;

        if (! $caja = CashRegister::find($id)) {
            $this->notify('error', 'La caja ya no existe.');

            return;
        }

        // Desactivarla con el turno abierto la sacaría del selector dejando los
        // cobros del día sin forma de llegar al arqueo.
        if ($caja->is_active && $caja->estaAbierta()) {
            $this->notify('error', "«{$caja->name}» tiene un turno abierto. Cerrá el arqueo antes de desactivarla.");

            return;
        }

        $caja->update(['is_active' => ! $caja->is_active]);
        $this->notify('success', $caja->is_active
            ? "Caja «{$caja->name}» activada."
            : "Caja «{$caja->name}» desactivada.");
    }

    public function delete(int $id): void
    {
        $this->feedback = null;

        if (! $caja = CashRegister::find($id)) {
            $this->notify('error', 'La caja ya no existe.');

            return;
        }

        if ($caja->tieneHistorial()) {
            $this->notify('error', "«{$caja->name}» tiene turnos registrados y no se puede eliminar: "
                . 'un arqueo cerrado es un documento contable. Desactivala y deja de aparecer en el selector.');

            return;
        }

        // La última caja de una sede la dejaría sin poder cobrar de contado.
        if (CashRegister::where('branch_id', $caja->branch_id)->count() === 1) {
            $this->notify('error', "«{$caja->name}» es la única caja de la sede: sin ella no se puede cobrar "
                . 'de contado ahí. Creá otra antes de eliminarla.');

            return;
        }

        $caja->delete();
        $this->notify('success', 'Caja eliminada.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'branch_id', 'name']);
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.cash-registers.cash-register-index', [
            'sedes' => Branch::with(['cashRegisters' => fn ($q) => $q->orderBy('name')])
                ->orderBy('name')
                ->get(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name', 'prefix']),
        ])->layout('layouts.app', ['title' => 'Cajas']);
    }
}
