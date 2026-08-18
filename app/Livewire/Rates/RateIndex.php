<?php

namespace App\Livewire\Rates;

use App\Models\Branch;
use App\Models\Rate;
use App\Services\Tarifario;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class RateIndex extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public ?int $origin_branch_id = null;
    public ?int $destination_branch_id = null;
    public string $shipment_type = '';
    public float $min_weight = 0;
    public ?float $max_weight = null;
    public float $price = 0;
    public float $price_per_extra_kg = 0;
    public bool $is_active = true;

    /** Cotizador: sirve para verificar qué tarifa gana antes de guardar. */
    public ?int $probe_origin = null;
    public ?int $probe_destination = null;
    public float $probe_weight = 1;
    public ?float $probe_length = null;
    public ?float $probe_width = null;
    public ?float $probe_height = null;
    public array $probeResult = [];

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    protected function rules(): array
    {
        return [
            'name' => 'nullable|string|max:100',
            'origin_branch_id' => 'nullable|exists:branches,id',
            // Nullable pero distinta: una tarifa de una sede a sí misma no
            // aplica a ningún envío posible.
            'destination_branch_id' => 'nullable|exists:branches,id|different:origin_branch_id',
            'shipment_type' => ['nullable', Rule::in(array_keys(Rate::SHIPMENT_TYPES))],
            'min_weight' => 'required|numeric|min:0',
            'max_weight' => 'nullable|numeric|gt:min_weight',
            'price' => 'required|numeric|min:0',
            'price_per_extra_kg' => 'nullable|numeric|min:0',
        ];
    }

    protected function messages(): array
    {
        return [
            'destination_branch_id.different' => 'El destino tiene que ser una sede distinta del origen: '
                . 'no existen envíos de una sede a sí misma.',
            'max_weight.gt' => 'El peso máximo tiene que ser mayor que el mínimo.',
            'price.required' => 'El precio es obligatorio.',
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
        $rate = Rate::find($id);

        if (! $rate) {
            $this->notify('error', 'La tarifa que intentás editar ya no existe.');

            return;
        }

        $this->resetErrorBag();
        $this->editingId = $rate->id;
        $this->name = (string) $rate->name;
        $this->origin_branch_id = $rate->origin_branch_id;
        $this->destination_branch_id = $rate->destination_branch_id;
        $this->shipment_type = (string) $rate->shipment_type;
        $this->min_weight = (float) $rate->min_weight;
        $this->max_weight = $rate->max_weight === null ? null : (float) $rate->max_weight;
        $this->price = (float) $rate->price;
        $this->price_per_extra_kg = (float) $rate->price_per_extra_kg;
        $this->is_active = $rate->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->feedback = null;
        $data = $this->validate();

        // Un tramo sin tope y sin cobro por kilo extra cobra lo mismo por 5 kg
        // que por 500: casi siempre es un descuido al cargar la tarifa.
        if ($this->max_weight === null && (float) $this->price_per_extra_kg <= 0) {
            throw ValidationException::withMessages([
                'price_per_extra_kg' => 'Sin peso máximo, hace falta un cobro por kilo adicional: '
                    . 'de lo contrario un paquete de 500 kg cuesta lo mismo que uno de 5.',
            ]);
        }

        try {
            Rate::updateOrCreate(
                ['id' => $this->editingId],
                $data + [
                    'shipment_type' => $this->shipment_type ?: null,
                    'is_active' => $this->is_active,
                ]
            );
        } catch (QueryException $e) {
            report($e);
            $this->notify('error', 'No se pudo guardar la tarifa.');

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->notify('success', 'Tarifa guardada correctamente.');
    }

    public function toggleActive(int $id): void
    {
        $this->feedback = null;
        $rate = Rate::find($id);

        if (! $rate) {
            $this->notify('error', 'La tarifa ya no existe.');

            return;
        }

        $rate->update(['is_active' => ! $rate->is_active]);
        $this->notify('success', $rate->is_active ? 'Tarifa activada.' : 'Tarifa desactivada.');
    }

    public function delete(int $id): void
    {
        $this->feedback = null;
        $rate = Rate::find($id);

        if (! $rate) {
            $this->notify('error', 'La tarifa ya no existe.');

            return;
        }

        $rate->delete();
        $this->notify('success', 'Tarifa eliminada.');
    }

    /** Prueba qué tarifa gana para una ruta y un peso concretos. */
    public function probe(Tarifario $tarifario): void
    {
        $this->probeResult = $tarifario->cotizar(
            $this->probe_origin ? Branch::find($this->probe_origin) : null,
            $this->probe_destination ? Branch::find($this->probe_destination) : null,
            (float) $this->probe_weight,
            $this->probe_length,
            $this->probe_width,
            $this->probe_height,
            null
        );
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'origin_branch_id', 'destination_branch_id',
            'shipment_type', 'max_weight',
        ]);
        $this->min_weight = 0;
        $this->price = 0;
        $this->price_per_extra_kg = 0;
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.rates.rate-index', [
            'rates' => Rate::with(['originBranch:id,name,prefix', 'destinationBranch:id,name,prefix'])
                ->orderBy('origin_branch_id')
                ->orderBy('destination_branch_id')
                ->orderBy('min_weight')
                ->get(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name', 'prefix']),
        ])->layout('layouts.app', ['title' => 'Tarifario']);
    }
}
