<?php

namespace App\Livewire\PackageTypes;

use App\Models\PackageType;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Configuración de los tipos de bulto que ofrece el formulario de guías.
 *
 * Va configurable y no quemado en el código porque cada operación recibe cosas
 * distintas: agregar «llanta» o «electrodoméstico» no puede exigir un
 * despliegue.
 */
class PackageTypeIndex extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public bool $is_fragile = false;
    public int $sort_order = 0;
    public bool $is_active = true;

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('package_types', 'name')->ignore($this->editingId),
            ],
            'sort_order' => 'nullable|integer|min:0|max:999',
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Ponele un nombre al tipo, por ejemplo «Caja».',
            'name.unique' => 'Ya existe un tipo con ese nombre.',
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
        // Al final de la lista: el orden existente no se altera solo.
        $this->sort_order = (int) PackageType::max('sort_order') + 1;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->feedback = null;

        if (! $tipo = PackageType::find($id)) {
            $this->notify('error', 'El tipo que intentás editar ya no existe.');

            return;
        }

        $this->resetErrorBag();
        $this->editingId = $tipo->id;
        $this->name = (string) $tipo->name;
        $this->is_fragile = (bool) $tipo->is_fragile;
        $this->sort_order = (int) $tipo->sort_order;
        $this->is_active = (bool) $tipo->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->feedback = null;
        $data = $this->validate();

        try {
            PackageType::updateOrCreate(
                ['id' => $this->editingId],
                $data + ['is_fragile' => $this->is_fragile, 'is_active' => $this->is_active]
            );
        } catch (QueryException $e) {
            report($e);
            $this->notify('error', 'No se pudo guardar el tipo de bulto.');

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->notify('success', 'Tipo de bulto guardado.');
    }

    public function toggleActive(int $id): void
    {
        $this->feedback = null;

        if (! $tipo = PackageType::find($id)) {
            $this->notify('error', 'El tipo ya no existe.');

            return;
        }

        // Sin ningún tipo activo el formulario de guías no tendría qué ofrecer.
        if ($tipo->is_active && PackageType::active()->count() === 1) {
            $this->notify('error', "«{$tipo->name}» es el único tipo activo: sin al menos uno, "
                . 'no se puede registrar ningún bulto.');

            return;
        }

        $tipo->update(['is_active' => ! $tipo->is_active]);
        $this->notify('success', $tipo->is_active
            ? "«{$tipo->name}» activado."
            : "«{$tipo->name}» desactivado: deja de aparecer en las guías nuevas.");
    }

    public function delete(int $id): void
    {
        $this->feedback = null;

        if (! $tipo = PackageType::withCount('items')->find($id)) {
            $this->notify('error', 'El tipo ya no existe.');

            return;
        }

        // Borrarlo dejaría en blanco el bulto de guías ya emitidas, incluidas
        // las que ya tienen comprobante enviado a Hacienda.
        if ($tipo->items_count > 0) {
            $this->notify('error', "«{$tipo->name}» está usado en {$tipo->items_count} "
                . ($tipo->items_count === 1 ? 'bulto ya registrado' : 'bultos ya registrados')
                . '. Desactivalo: deja de ofrecerse sin borrar lo que ya se emitió.');

            return;
        }

        $tipo->delete();
        $this->notify('success', 'Tipo de bulto eliminado.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'is_fragile', 'sort_order']);
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.package-types.package-type-index', [
            'tipos' => PackageType::withCount('items')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ])->layout('layouts.app', ['title' => 'Tipos de bulto']);
    }
}
