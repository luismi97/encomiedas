<?php

namespace App\Livewire\ShippingRoutes;

use App\Models\Branch;
use App\Models\ShippingRoute;
use App\Rules\DeLaEmpresa;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Configuración de las rutas predefinidas.
 *
 * Quien atiende el mostrador no entra acá: define las rutas una vez el
 * administrador, y el cajero solo las elige.
 */
class ShippingRouteIndex extends Component
{
    public bool $showForm = false;
    public $editingId = null;

    public string $name = '';
    public $origin_branch_id = null;
    public $destination_branch_id = null;
    public $transit_days = null;
    public bool $is_active = true;

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:80',
            'origin_branch_id' => ['required', DeLaEmpresa::en('branches')],
            'destination_branch_id' => [
                'required', 'different:origin_branch_id', DeLaEmpresa::en('branches'),
                // El par no se repite: dos rutas iguales con nombres distintos
                // obligan a adivinar cuál usar, y ninguna de las dos está mal.
                Rule::unique('shipping_routes', 'destination_branch_id')
                    ->where(fn ($q) => $q
                        ->where('company_id', CompanyContext::id())
                        ->where('origin_branch_id', $this->origin_branch_id))
                    ->ignore($this->editingId),
            ],
            'transit_days' => 'nullable|integer|min:1|max:60',
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Ponele un nombre a la ruta, por ejemplo «Limón directo».',
            'origin_branch_id.required' => 'Elegí la sede de origen.',
            'destination_branch_id.required' => 'Elegí la sede de destino.',
            'destination_branch_id.different' => 'El destino tiene que ser una sede distinta del origen.',
            'destination_branch_id.unique' => 'Ya existe una ruta entre esas dos sedes. Editá esa en vez de crear otra.',
            'transit_days.max' => 'Sesenta días de tránsito es más un extravío que una ruta.',
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

        if (! $ruta = ShippingRoute::find($id)) {
            $this->notify('error', 'La ruta que intentás editar ya no existe.');

            return;
        }

        $this->resetErrorBag();
        $this->editingId = $ruta->id;
        $this->name = (string) $ruta->name;
        $this->origin_branch_id = $ruta->origin_branch_id;
        $this->destination_branch_id = $ruta->destination_branch_id;
        $this->transit_days = $ruta->transit_days;
        $this->is_active = (bool) $ruta->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->feedback = null;
        $data = $this->validate();

        try {
            ShippingRoute::updateOrCreate(
                ['id' => $this->editingId],
                $data + ['is_active' => $this->is_active]
            );
        } catch (QueryException $e) {
            report($e);
            $this->notify('error', 'No se pudo guardar la ruta.');

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->notify('success', 'Ruta guardada. Ya se puede elegir al crear una guía.');
    }

    public function toggleActive(int $id): void
    {
        $this->feedback = null;

        if (! $ruta = ShippingRoute::find($id)) {
            $this->notify('error', 'La ruta ya no existe.');

            return;
        }

        $ruta->update(['is_active' => ! $ruta->is_active]);

        $this->notify('success', $ruta->is_active
            ? "«{$ruta->name}» activada."
            : "«{$ruta->name}» desactivada: deja de ofrecerse en las guías nuevas. "
                . 'Las ya emitidas no cambian.');
    }

    public function delete(int $id): void
    {
        $this->feedback = null;

        if (! $ruta = ShippingRoute::withCount('invoices')->find($id)) {
            $this->notify('error', 'La ruta ya no existe.');

            return;
        }

        // Borrarla dejaría sin fecha prometida a guías que ya salieron con ella.
        if ($ruta->invoices_count > 0) {
            $this->notify('error', "«{$ruta->name}» se usó en {$ruta->invoices_count} "
                . ($ruta->invoices_count === 1 ? 'guía' : 'guías')
                . '. Desactivala: deja de ofrecerse sin tocar lo ya emitido.');

            return;
        }

        $ruta->delete();
        $this->notify('success', 'Ruta eliminada.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'origin_branch_id', 'destination_branch_id', 'transit_days']);
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.shipping-routes.shipping-route-index', [
            'rutas' => ShippingRoute::with(['originBranch', 'destinationBranch'])
                ->withCount('invoices')
                ->orderByDesc('is_active')
                ->orderByDesc('invoices_count')
                ->get(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name', 'prefix']),
        ])->layout('layouts.app', ['title' => 'Rutas']);
    }
}
