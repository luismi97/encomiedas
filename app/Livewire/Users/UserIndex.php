<?php

namespace App\Livewire\Users;

use App\Rules\DeLaEmpresa;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Livewire\Concerns\ScrollInfinito;
use Livewire\Component;

class UserIndex extends Component
{
    // Sin esto los enlaces de página son <a> comunes, y después de cualquier
    // acción de Livewire apuntan a /livewire/update?page=2: un GET a una ruta
    // que solo acepta POST, o sea 405.
    use ScrollInfinito;

    public bool $showForm = false;
    public $editingId = null;

    public string $name = '';
    public string $username = '';
    public string $email = '';
    public string $password = '';
    public string $role = 'repartidor';
    public $branch_id = null;
    public string $phone = '';
    public bool $is_active = true;
    public bool $can_collect = true;

    /** Sedes adicionales que atiende un dependiente (casillas). */
    public array $sedesExtra = [];

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
            // ROLES_ASIGNABLES y no ROLES: con el superadministrador en la
            // lista, cualquier administrador podría fabricarse una cuenta sin
            // empresa —y ver las de todos los demás clientes—.
            'role' => ['required', Rule::in(array_keys(User::ROLES_ASIGNABLES))],
            'branch_id' => ['nullable', DeLaEmpresa::en('branches')],
            'phone' => 'nullable|string|max:30',
            'sedesExtra' => 'array',
            'sedesExtra.*' => ['integer', DeLaEmpresa::en('branches')],
        ];
    }

    /**
     * Pasar a cajero arranca cobrando.
     *
     * La casilla conservaba lo que traía el usuario, así que al editar a alguien y
     * cambiarle el rol a cajero podía quedar desmarcada sin que nadie la tocara, y
     * esa persona no podía abrir caja. Quien no cobra es la excepción: se desmarca
     * a propósito.
     */
    public function updatedRole(string $rol): void
    {
        if ($rol === User::ROLE_CAJERO) {
            $this->can_collect = true;
        }
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
        $this->can_collect = $user->can_collect !== false;
        $this->sedesExtra = $user->branches()->pluck('branches.id')->map(fn ($id) => (string) $id)->all();
        $this->showForm = true;
    }

    public function save(): void
    {
        // Un cajero sin sede no tendría contra cuál validar su caja: terminaría
        // operando la de cualquiera.
        if (in_array($this->role, User::ROLES_CON_SEDE, true) && ! $this->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => 'Un ' . strtolower(User::ROLES[$this->role]) . ' necesita sede asignada: '
                    . 'solo puede operar la caja y las encomiendas de la suya.',
            ]);
        }

        $data = $this->validate();
        unset($data['sedesExtra']);

        $data['username'] = $data['username'] !== '' ? $data['username'] : null;

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $data['is_active'] = $this->is_active;
        // Solo distingue a los cajeros: el administrador siempre cobra y los
        // demás roles nunca.
        $data['can_collect'] = $this->role !== User::ROLE_CAJERO || $this->can_collect;

        $usuario = User::updateOrCreate(['id' => $this->editingId], $data);

        // Solo cajero y dependiente atienden varias sedes; a los demás se les
        // vacían para que un cambio de rol no les deje acceso a sedes ajenas.
        $usuario->branches()->sync(in_array($this->role, User::ROLES_MULTISEDE, true)
            ? collect($this->sedesExtra)->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === (int) $this->branch_id)->values()->all()
            : []);

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
        $this->reset(['editingId', 'name', 'username', 'email', 'password', 'branch_id', 'phone', 'sedesExtra']);
        $this->role = 'repartidor';
        $this->is_active = true;
        $this->can_collect = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        $tanda = $this->tanda(User::with(['branch', 'branches'])->orderBy('name'));

        return view('livewire.users.user-index', [
            'users' => $tanda['items'],
            'scroll' => $tanda,
            'branches' => Branch::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Usuarios']);
    }
}
