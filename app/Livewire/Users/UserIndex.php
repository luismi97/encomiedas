<?php

namespace App\Livewire\Users;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;

class UserIndex extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $username = '';
    public string $email = '';
    public string $password = '';
    public string $role = 'repartidor';
    public ?int $branch_id = null;
    public string $phone = '';
    public bool $is_active = true;

    protected function rules(): array
    {
        $usernameRules = ['nullable', 'alpha_dash', 'max:50'];
        if ($this->username !== '') {
            $usernameRules[] = Rule::unique('users', 'username')->ignore($this->editingId);
        }

        return [
            'name' => 'required|string|max:150',
            'username' => $usernameRules,
            'email' => 'required|email|unique:users,email,' . $this->editingId,
            'password' => $this->editingId ? 'nullable|string|min:6' : 'required|string|min:6',
            'role' => 'required|in:admin,repartidor',
            'branch_id' => 'nullable|exists:branches,id',
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
        $user = User::findOrFail($id);
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->username = (string) $user->username;
        $this->email = $user->email;
        $this->password = '';
        $this->role = $user->role;
        $this->branch_id = $user->branch_id;
        $this->phone = (string) $user->phone;
        $this->is_active = $user->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        $data['username'] = $data['username'] !== '' ? $data['username'] : null;

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $data['is_active'] = $this->is_active;

        User::updateOrCreate(['id' => $this->editingId], $data);

        $this->showForm = false;
        $this->resetForm();
        session()->flash('success', 'Usuario guardado correctamente.');
    }

    public function toggleActive(int $id): void
    {
        if ($id === auth()->id()) {
            session()->flash('error', 'No puede desactivar su propia cuenta.');
            return;
        }
        $user = User::findOrFail($id);
        $user->update(['is_active' => !$user->is_active]);
    }

    public function delete(int $id): void
    {
        if ($id === auth()->id()) {
            session()->flash('error', 'No puede eliminar su propia cuenta.');
            return;
        }
        User::findOrFail($id)->delete();
        session()->flash('success', 'Usuario eliminado.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'username', 'email', 'password', 'branch_id', 'phone']);
        $this->role = 'repartidor';
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.users.user-index', [
            'users' => User::with('branch')->orderBy('name')->paginate(10),
            'branches' => Branch::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Usuarios']);
    }
}
