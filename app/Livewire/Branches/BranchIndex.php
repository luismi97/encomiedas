<?php

namespace App\Livewire\Branches;

use App\Models\Branch;
use Livewire\Component;
use Livewire\WithPagination;

class BranchIndex extends Component
{
    use WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $sucursal_code = '001';
    public string $terminal_code = '00001';
    public string $address = '';
    public string $province = '';
    public string $canton = '';
    public string $district = '';
    public string $phone = '';
    public bool $is_active = true;

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'sucursal_code' => 'required|string|max:3',
            'terminal_code' => 'required|string|max:5',
            'address' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:1',
            'canton' => 'nullable|string|max:2',
            'district' => 'nullable|string|max:2',
            'phone' => 'nullable|string|max:30',
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $branch = Branch::findOrFail($id);
        $this->editingId = $branch->id;
        $this->name = $branch->name;
        $this->sucursal_code = $branch->sucursal_code;
        $this->terminal_code = $branch->terminal_code;
        $this->address = (string) $branch->address;
        $this->province = (string) $branch->province;
        $this->canton = (string) $branch->canton;
        $this->district = (string) $branch->district;
        $this->phone = (string) $branch->phone;
        $this->is_active = $branch->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        Branch::updateOrCreate(['id' => $this->editingId], $data + ['is_active' => $this->is_active]);

        $this->showForm = false;
        $this->resetForm();
        session()->flash('success', 'Sucursal guardada correctamente.');
    }

    public function toggleActive(int $id): void
    {
        $branch = Branch::findOrFail($id);
        $branch->update(['is_active' => !$branch->is_active]);
    }

    public function delete(int $id): void
    {
        Branch::findOrFail($id)->delete();
        session()->flash('success', 'Sucursal eliminada.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'address', 'province', 'canton', 'district', 'phone']);
        $this->sucursal_code = '001';
        $this->terminal_code = '00001';
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.branches.branch-index', [
            'branches' => Branch::orderBy('name')->paginate(10),
        ])->layout('layouts.app', ['title' => 'Sucursales']);
    }
}
