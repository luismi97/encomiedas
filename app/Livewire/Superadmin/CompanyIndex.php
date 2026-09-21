<?php

namespace App\Livewire\Superadmin;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\CompanyEraser;
use App\Services\CompanyProvisioner;
use App\Support\CompanyContext;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * El panel del dueño del sistema: alta y control de las empresas cliente.
 *
 * Es la pantalla que reemplaza a la instalación por cliente. Dar de alta a
 * «Transportes López» es llenar este formulario, no clonar el proyecto, crear
 * una base, configurar un dominio y recorrer seis pantallas de ajustes.
 */
class CompanyIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showForm = false;
    public ?int $editingId = null;

    // Datos de la empresa
    public string $name = '';
    public string $legal_name = '';
    public string $identification = '';
    public string $email = '';
    public string $phone = '';
    public string $expires_on = '';
    public string $notes = '';

    // Datos del primer acceso (solo al crear)
    public string $admin_name = '';
    public string $admin_email = '';
    public string $admin_password = '';
    public string $branch_name = 'Sede principal';
    public string $branch_prefix = '';

    /** Empresa cuya eliminación se está confirmando. */
    public ?int $deletingId = null;
    public string $deleteConfirmation = '';

    /**
     * Empresa a la que se le está reescribiendo la contraseña del
     * administrador. Es el camino de soporte: el cliente perdió el acceso y no
     * tiene a mano el correo de recuperación.
     */
    public ?int $passwordForId = null;

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    protected function rules(): array
    {
        $reglas = [
            'name'           => 'required|string|max:150',
            'legal_name'     => 'nullable|string|max:150',
            'identification' => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:150',
            'phone'          => 'nullable|string|max:30',
            'expires_on'     => 'nullable|date',
            'notes'          => 'nullable|string|max:1000',
        ];

        if ($this->editingId) {
            return $reglas;
        }

        return $reglas + [
            'admin_name'     => 'required|string|max:150',
            // Único en todo el sistema: el correo es con lo que se entra, y no
            // hay a cuál de dos empresas autenticar si se repite.
            'admin_email'    => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'admin_password' => 'required|string|min:8',
            'branch_name'    => 'required|string|max:150',
            'branch_prefix'  => 'nullable|string|regex:/^[A-Za-z]{2,4}$/',
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required'           => 'El nombre de la empresa es obligatorio.',
            'admin_name.required'     => 'El nombre del administrador es obligatorio.',
            'admin_email.required'    => 'El correo del administrador es obligatorio: es con lo que va a entrar.',
            'admin_email.unique'      => 'Ese correo ya tiene cuenta en el sistema. Usá otro para esta empresa.',
            'admin_password.required' => 'Poné una contraseña inicial: se la vas a dictar al cliente.',
            'admin_password.min'      => 'La contraseña necesita al menos 8 caracteres.',
            'branch_name.required'    => 'La empresa necesita al menos una sede para poder crear guías.',
            'branch_prefix.regex'     => 'El prefijo son de 2 a 4 letras (ej. SJ, LIM). Si lo dejás vacío se deduce del nombre.',
        ];
    }

    private function avisar(string $tipo, string $mensaje): void
    {
        $this->feedbackType = $tipo;
        $this->feedback = $mensaje;
    }

    public function dismissFeedback(): void
    {
        $this->feedback = null;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $empresa = Company::find($id);

        if (! $empresa) {
            $this->avisar('error', 'Esa empresa ya no existe.');

            return;
        }

        $this->resetErrorBag();
        $this->editingId = $empresa->id;
        $this->name = $empresa->name;
        $this->legal_name = (string) $empresa->legal_name;
        $this->identification = (string) $empresa->identification;
        $this->email = (string) $empresa->email;
        $this->phone = (string) $empresa->phone;
        $this->expires_on = $empresa->expires_on?->format('Y-m-d') ?? '';
        $this->notes = (string) $empresa->notes;
        $this->showForm = true;
    }

    public function save(CompanyProvisioner $provisioner): void
    {
        $datos = $this->validate();

        if ($this->editingId) {
            $empresa = Company::find($this->editingId);

            if (! $empresa) {
                $this->avisar('error', 'Esa empresa ya no existe.');
                $this->cancel();

                return;
            }

            $empresa->update([
                'name'           => $datos['name'],
                'legal_name'     => $datos['legal_name'] ?: null,
                'identification' => $datos['identification'] ?: null,
                'email'          => $datos['email'] ?: null,
                'phone'          => $datos['phone'] ?: null,
                'expires_on'     => $datos['expires_on'] ?: null,
                'notes'          => $datos['notes'] ?: null,
            ]);

            $this->cancel();
            $this->avisar('success', "«{$empresa->name}» actualizada.");

            return;
        }

        try {
            $empresa = $provisioner->crear([
                'name'           => $datos['name'],
                'legal_name'     => $datos['legal_name'] ?: null,
                'identification' => $datos['identification'] ?: null,
                'email'          => $datos['email'] ?: null,
                'phone'          => $datos['phone'] ?: null,
                'expires_on'     => $datos['expires_on'] ?: null,
                'notes'          => $datos['notes'] ?: null,
                'admin_name'     => $datos['admin_name'],
                'admin_email'    => $datos['admin_email'],
                'admin_username' => null,
                'admin_password' => $datos['admin_password'],
                'branch_name'    => $datos['branch_name'],
                'branch_prefix'  => $datos['branch_prefix'] ?: null,
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->avisar('error', 'No se pudo crear la empresa: ' . $e->getMessage());

            return;
        }

        $correo = $datos['admin_email'];
        $this->cancel();
        $this->avisar('success', "«{$empresa->name}» creada y lista para operar. "
            . "Entregale el acceso: {$correo}");
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $empresa = Company::find($id);

        if (! $empresa) {
            $this->avisar('error', 'Esa empresa ya no existe.');

            return;
        }

        $empresa->update(['is_active' => ! $empresa->is_active]);

        $this->avisar('success', $empresa->is_active
            ? "«{$empresa->name}» reactivada: su gente ya puede entrar."
            : "«{$empresa->name}» suspendida. Sus usuarios no van a poder entrar, pero no se borró nada.");
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->deleteConfirmation = '';
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->deleteConfirmation = '';
    }

    /**
     * Elimina una empresa y todo lo suyo.
     *
     * Existe para deshacer una empresa de prueba mal cargada, no para dar de
     * baja a un cliente: lo de un cliente que se va es suspenderlo, porque sus
     * comprobantes están transmitidos a Hacienda y hay que poder consultarlos.
     * Por eso pide escribir el nombre: es la única acción del panel que no se
     * puede deshacer.
     */
    public function delete(CompanyEraser $eraser): void
    {
        $empresa = Company::find($this->deletingId);

        if (! $empresa) {
            $this->avisar('error', 'Esa empresa ya no existe.');
            $this->cancelDelete();

            return;
        }

        if (trim($this->deleteConfirmation) !== $empresa->name) {
            $this->avisar('error', 'El nombre no coincide: no se eliminó nada.');

            return;
        }

        $nombre = $empresa->name;

        try {
            $eraser->borrar($empresa);
        } catch (Throwable $e) {
            report($e);
            $this->avisar('error', "No se pudo eliminar «{$nombre}»: " . $e->getMessage()
                . ' Suspendela en su lugar.');
            $this->cancelDelete();

            return;
        }

        $this->cancelDelete();
        $this->avisar('success', "«{$nombre}» eliminada junto con toda su información.");
    }

    public function render()
    {
        // Sin empresa en contexto el ámbito global no filtra, que es justo lo
        // que hace falta acá: los conteos son de todas las empresas.
        $empresas = CompanyContext::sinAlcance(fn () => Company::query()
            ->when($this->search, fn ($q) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('identification', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")
            ))
            ->withCount(['users', 'branches', 'invoices'])
            ->orderBy('name')
            ->paginate(15));

        return view('livewire.superadmin.company-index', [
            'empresas' => $empresas,
            'totales'  => CompanyContext::sinAlcance(fn () => [
                'empresas' => Company::count(),
                'activas'  => Company::active()->count(),
                'guias'    => Invoice::count(),
            ]),
        ])->layout('layouts.app', ['title' => 'Empresas']);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'legal_name', 'identification', 'email', 'phone',
            'expires_on', 'notes', 'admin_name', 'admin_email', 'admin_password',
            'branch_prefix', 'passwordForId',
        ]);
        $this->branch_name = 'Sede principal';
        $this->resetErrorBag();
    }
}
