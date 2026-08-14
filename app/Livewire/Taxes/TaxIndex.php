<?php

namespace App\Livewire\Taxes;

use App\Models\Tax;
use Livewire\Component;

class TaxIndex extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public float $percent = 13.0;
    public string $hacienda_code = '08';
    public bool $is_default = false;
    public bool $is_active = true;

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'percent' => 'required|numeric|min:0|max:100',
            'hacienda_code' => 'required|string|max:2',
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $tax = Tax::findOrFail($id);
        $this->editingId = $tax->id;
        $this->name = $tax->name;
        $this->percent = (float) $tax->percent;
        $this->hacienda_code = $tax->hacienda_code;
        $this->is_default = $tax->is_default;
        $this->is_active = $tax->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->is_default) {
            Tax::query()->update(['is_default' => false]);
        }

        Tax::updateOrCreate(['id' => $this->editingId], $data + [
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
        ]);

        $this->showForm = false;
        $this->resetForm();
        session()->flash('success', 'Impuesto guardado correctamente.');
    }

    public function delete(int $id): void
    {
        Tax::findOrFail($id)->delete();
        session()->flash('success', 'Impuesto eliminado.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name']);
        $this->percent = 13.0;
        $this->hacienda_code = '08';
        $this->is_default = false;
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.taxes.tax-index', [
            'taxes' => Tax::orderByDesc('is_default')->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Impuestos']);
    }
}
