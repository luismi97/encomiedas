<?php

namespace App\Livewire\Branches;

use App\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class BranchIndex extends Component
{
    use WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $prefix = '';
    public string $sucursal_code = '001';
    public string $terminal_code = '00001';
    public string $address = '';
    public string $province = '';
    public string $canton = '';
    public string $district = '';
    public string $phone = '';
    public int $receipt_paper_width = 80;

    /** business_hours como arreglo editable: [dia => ['abre'=>..,'cierra'=>..]] */
    public array $business_hours = [];
    public bool $is_active = true;

    /** Bloquea los codigos de Hacienda cuando la sucursal ya emitio comprobantes. */
    public bool $codesLocked = false;

    /**
     * Livewire re-renderiza solo el componente, no el layout: un mensaje flash
     * de sesion no se veria hasta recargar la pagina. El aviso vive como estado
     * del componente para que se pinte en la misma respuesta.
     */
    public ?string $feedback = null;
    public string $feedbackType = 'success';

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            // Va en el código guía que ve el cliente (SJ-LIM-00005), así que
            // tiene que ser corto, en letras y único entre sedes.
            'prefix' => [
                'required', 'string', 'regex:/^[A-Za-z]{2,4}$/',
                Rule::unique('branches', 'prefix')->ignore($this->editingId),
            ],
            'sucursal_code' => [
                'required', 'string', 'regex:/^\d{3}$/',
                Rule::unique('branches', 'sucursal_code')
                    ->where(fn ($q) => $q->where('terminal_code', $this->terminal_code))
                    ->ignore($this->editingId),
            ],
            'terminal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            'address' => 'nullable|string|max:255',
            'province' => 'nullable|regex:/^\d$/',
            'canton' => 'nullable|regex:/^\d{2}$/',
            'district' => 'nullable|regex:/^\d{2}$/',
            'phone' => 'nullable|string|max:30',
            'receipt_paper_width' => ['required', Rule::in(Branch::PAPER_WIDTHS)],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'El nombre de la sucursal es obligatorio.',
            'prefix.required' => 'El prefijo es obligatorio: es lo que identifica la sede en el código guía.',
            'prefix.regex' => 'El prefijo son de 2 a 4 letras, sin números ni espacios (ej. SJ, LIM, HER).',
            'prefix.unique' => 'Otra sucursal ya usa ese prefijo: los códigos guía se confundirían.',
            'sucursal_code.required' => 'El código de sucursal es obligatorio.',
            'sucursal_code.regex' => 'El código de sucursal debe tener exactamente 3 dígitos (ej. 001).',
            'sucursal_code.unique' => 'Ya existe otra sucursal con esa combinación de código de sucursal y terminal.',
            'terminal_code.required' => 'El código de terminal es obligatorio.',
            'terminal_code.regex' => 'El código de terminal debe tener exactamente 5 dígitos (ej. 00001).',
            'province.regex' => 'La provincia debe ser 1 dígito del 1 al 7.',
            'canton.regex' => 'El cantón debe tener 2 dígitos (ej. 01).',
            'district.regex' => 'El distrito debe tener 2 dígitos (ej. 01).',
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

    public function edit(int $id): void
    {
        $this->feedback = null;
        $branch = Branch::find($id);

        if (! $branch) {
            $this->notify('error', 'La sucursal que intentás editar ya no existe.');

            return;
        }

        $this->resetErrorBag();
        $this->editingId = $branch->id;
        $this->name = $branch->name;
        $this->prefix = (string) $branch->prefix;
        $this->sucursal_code = $branch->sucursal_code;
        $this->terminal_code = $branch->terminal_code;
        $this->address = (string) $branch->address;
        $this->province = (string) $branch->province;
        $this->canton = (string) $branch->canton;
        $this->district = (string) $branch->district;
        $this->phone = (string) $branch->phone;
        $this->receipt_paper_width = $branch->receiptPaperWidthMm();
        $this->business_hours = $this->horarioEditable($branch->business_hours ?? []);
        $this->is_active = $branch->is_active;
        $this->codesLocked = $branch->hasHaciendaHistory();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->feedback = null;
        $branch = $this->editingId ? Branch::find($this->editingId) : null;

        if ($this->editingId && ! $branch) {
            $this->notify('error', 'La sucursal que intentás guardar ya no existe.');
            $this->showForm = false;
            $this->resetForm();

            return;
        }

        // Los codigos viajan dentro de la clave numerica de Hacienda. Si ya se
        // emitio un comprobante con ellos, cambiarlos desalinea el consecutivo.
        if ($branch && $branch->hasHaciendaHistory()) {
            if ($this->sucursal_code !== $branch->sucursal_code || $this->terminal_code !== $branch->terminal_code) {
                $this->sucursal_code = $branch->sucursal_code;
                $this->terminal_code = $branch->terminal_code;

                throw ValidationException::withMessages([
                    'sucursal_code' => 'Esta sucursal ya emitió comprobantes electrónicos con los códigos '
                        . $branch->codeLabel() . '. Cambiarlos rompería el consecutivo de Hacienda. '
                        . 'Creá una sucursal nueva si necesitás otros códigos.',
                ]);
            }
        }

        // Desactivar una sucursal con encomiendas en curso las deja sin punto operativo.
        if ($branch && $branch->is_active && ! $this->is_active) {
            $inProgress = $branch->inProgressInvoices()->count();

            if ($inProgress > 0) {
                $this->is_active = true;

                throw ValidationException::withMessages([
                    'is_active' => "No se puede desactivar «{$branch->name}»: tiene {$inProgress} "
                        . ($inProgress === 1 ? 'encomienda pendiente o en camino' : 'encomiendas pendientes o en camino')
                        . '. Cerrá o reasigná esas encomiendas primero.',
                ]);
            }
        }

        $data = $this->validate();
        $data['prefix'] = strtoupper($data['prefix']);

        // Un día sin hora de apertura es un día cerrado: se guarda como null en
        // vez de una fila vacía que después hay que interpretar.
        $data['business_hours'] = collect($this->business_hours)
            ->map(fn ($h) => filled($h['abre'] ?? null) ? ['abre' => $h['abre'], 'cierra' => $h['cierra'] ?: '23:59'] : null)
            ->all();

        try {
            Branch::updateOrCreate(
                ['id' => $this->editingId],
                $data + ['is_active' => $this->is_active]
            );
        } catch (QueryException $e) {
            report($e);
            $this->notify('error', 'No se pudo guardar la sucursal. Verificá que los códigos no estén repetidos.');

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->notify('success', 'Sucursal guardada correctamente.');
    }

    public function toggleActive(int $id): void
    {
        $this->feedback = null;
        $branch = Branch::find($id);

        if (! $branch) {
            $this->notify('error', 'La sucursal ya no existe.');

            return;
        }

        if ($branch->is_active) {
            $inProgress = $branch->inProgressInvoices()->count();

            if ($inProgress > 0) {
                $this->notify('error', "No se puede desactivar «{$branch->name}»: tiene {$inProgress} "
                    . ($inProgress === 1 ? 'encomienda pendiente o en camino' : 'encomiendas pendientes o en camino')
                    . '. Cerrá o reasigná esas encomiendas primero.');

                return;
            }
        }

        $branch->update(['is_active' => ! $branch->is_active]);
        $this->notify('success', $branch->is_active
            ? "Sucursal «{$branch->name}» activada."
            : "Sucursal «{$branch->name}» desactivada.");
    }

    public function delete(int $id): void
    {
        $this->feedback = null;
        $branch = Branch::withCount('users')->find($id);

        if (! $branch) {
            $this->notify('error', 'La sucursal ya no existe.');

            return;
        }

        if ($blocker = $this->deleteBlocker($branch)) {
            $this->notify('error', $blocker);

            return;
        }

        try {
            $branch->delete();
        } catch (QueryException $e) {
            // Red de seguridad: cualquier FK que no cubrimos arriba cae aca en vez de un 500.
            report($e);
            $this->notify('error', "No se puede eliminar «{$branch->name}» porque tiene información asociada. Desactivala en su lugar.");

            return;
        }

        $this->notify('success', 'Sucursal eliminada.');
    }

    /** Devuelve el motivo por el que la sucursal no se puede borrar, o null si si se puede. */
    private function deleteBlocker(Branch $branch): ?string
    {
        $inProgress = $branch->inProgressInvoices()->count();

        if ($inProgress > 0) {
            return "No se puede eliminar «{$branch->name}»: tiene {$inProgress} "
                . ($inProgress === 1 ? 'encomienda pendiente o en camino' : 'encomiendas pendientes o en camino')
                . '. Cerrá o reasigná esas encomiendas primero.';
        }

        $total = $branch->allInvoices()->count();

        if ($total > 0) {
            return "No se puede eliminar «{$branch->name}»: tiene {$total} "
                . ($total === 1 ? 'encomienda registrada en su historial' : 'encomiendas registradas en su historial')
                . '. Desactivala para sacarla de circulación sin perder el historial.';
        }

        if ($branch->hasHaciendaHistory()) {
            return "No se puede eliminar «{$branch->name}»: ya tiene consecutivos de Hacienda emitidos con los códigos "
                . $branch->codeLabel() . '. Desactivala en su lugar.';
        }

        if ($branch->users_count > 0) {
            return "No se puede eliminar «{$branch->name}»: tiene {$branch->users_count} "
                . ($branch->users_count === 1 ? 'usuario asignado' : 'usuarios asignados')
                . '. Reasignalos a otra sucursal primero.';
        }

        return null;
    }

    /** Normaliza a los 7 días para que la vista no tenga que preguntar. */
    private function horarioEditable(?array $guardado): array
    {
        $guardado ??= [];
        $horario = [];

        foreach (array_keys(Branch::DIAS) as $dia) {
            $valor = $guardado[$dia] ?? $guardado[(string) $dia] ?? null;
            $horario[$dia] = [
                'abre'   => $valor['abre'] ?? '',
                'cierra' => $valor['cierra'] ?? '',
            ];
        }

        return $horario;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'prefix', 'address', 'province', 'canton', 'district', 'phone']);
        $this->sucursal_code = '001';
        $this->terminal_code = '00001';
        $this->receipt_paper_width = 80;
        $this->business_hours = $this->horarioEditable([]);
        $this->is_active = true;
        $this->codesLocked = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.branches.branch-index', [
            'branches' => Branch::query()
                ->withCount('users')
                ->orderBy('name')
                ->paginate(10),
        ])->layout('layouts.app', ['title' => 'Sucursales']);
    }
}
