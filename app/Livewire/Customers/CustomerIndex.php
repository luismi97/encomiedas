<?php

namespace App\Livewire\Customers;

use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class CustomerIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filterCondition = '';

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $commercial_name = '';
    public string $identification_type = '01';
    public string $identification = '';
    public string $activity_code = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public ?int $branch_id = null;
    public string $payment_condition = Customer::PAYMENT_CASH;
    public float $credit_limit = 0;
    public ?int $credit_cutoff_day = null;
    public string $notes = '';
    public bool $is_active = true;

    /** Livewire re-renderiza el componente, no el layout: el aviso vive aquí. */
    public ?string $feedback = null;
    public string $feedbackType = 'success';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterCondition(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'commercial_name' => 'nullable|string|max:150',
            'identification_type' => ['nullable', Rule::in(array_keys(Customer::IDENTIFICATION_TYPES))],
            'identification' => [
                'nullable', 'regex:/^\d{9,12}$/',
                Rule::unique('customers', 'identification')->ignore($this->editingId),
            ],
            'activity_code' => ['nullable', 'regex:/^(?:\d{6}|\d{4}\.\d)$/'],
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'payment_condition' => ['required', Rule::in(array_keys(Customer::PAYMENT_CONDITIONS))],
            'credit_limit' => 'nullable|numeric|min:0',
            'credit_cutoff_day' => 'nullable|integer|min:1|max:31',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'El nombre o razón social es obligatorio.',
            'identification.regex' => 'La identificación son de 9 a 12 dígitos, sin guiones ni espacios.',
            'identification.unique' => 'Ya hay otro cliente registrado con esa identificación.',
            'activity_code.regex' => 'El código de actividad son 6 dígitos (ej. 492300) o 4 con decimal (ej. 4923.0).',
            'credit_cutoff_day.max' => 'El día de corte va del 1 al 31.',
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
        $customer = Customer::find($id);

        if (! $customer) {
            $this->notify('error', 'El cliente que intentás editar ya no existe.');

            return;
        }

        $this->resetErrorBag();
        $this->editingId = $customer->id;
        $this->name = $customer->name;
        $this->commercial_name = (string) $customer->commercial_name;
        $this->identification_type = (string) ($customer->identification_type ?: '01');
        $this->identification = (string) $customer->identification;
        $this->activity_code = (string) $customer->activity_code;
        $this->email = (string) $customer->email;
        $this->phone = (string) $customer->phone;
        $this->address = (string) $customer->address;
        $this->branch_id = $customer->branch_id;
        $this->payment_condition = $customer->payment_condition;
        $this->credit_limit = (float) $customer->credit_limit;
        $this->credit_cutoff_day = $customer->credit_cutoff_day;
        $this->notes = (string) $customer->notes;
        $this->is_active = $customer->is_active;
        $this->showForm = true;
    }

    /**
     * La cédula se digita con guiones con toda naturalidad: se limpia antes de
     * validar para no rechazar algo que sí es válido.
     */
    private function normalize(): void
    {
        $this->identification = preg_replace('/\D/', '', $this->identification);

        if ($this->identification === '') {
            $this->identification_type = '';
        }
    }

    public function save(): void
    {
        $this->feedback = null;
        $this->normalize();

        // Un cliente de crédito sin cédula no se puede facturar: Hacienda exige
        // receptor identificado en la Factura Electrónica.
        if ($this->payment_condition === Customer::PAYMENT_CREDIT && blank($this->identification)) {
            throw ValidationException::withMessages([
                'identification' => 'Un cliente de crédito necesita identificación: sin ella no se le puede '
                    . 'emitir Factura Electrónica al cierre del período.',
            ]);
        }

        $data = $this->validate();

        $esCredito = $this->payment_condition === Customer::PAYMENT_CREDIT;

        try {
            // array_merge y no `+`: el operador de unión CONSERVA la clave que
            // ya venía en $data, así que los valores calculados de abajo se
            // perderían silenciosamente.
            Customer::updateOrCreate(
                ['id' => $this->editingId],
                array_merge($data, [
                    // Null y no cadena vacía: el índice único admite varios
                    // null (clientes de contado sin cédula) pero un solo ''.
                    'identification' => blank($this->identification) ? null : $this->identification,
                    'identification_type' => blank($this->identification) ? null : $this->identification_type,
                    'is_active' => $this->is_active,
                    'credit_limit' => $esCredito ? $this->credit_limit : 0,
                    'credit_cutoff_day' => $esCredito ? $this->credit_cutoff_day : null,
                ])
            );
        } catch (QueryException $e) {
            report($e);
            $this->notify('error', 'No se pudo guardar el cliente. Verificá que la identificación no esté repetida.');

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->notify('success', 'Cliente guardado correctamente.');
    }

    public function toggleActive(int $id): void
    {
        $this->feedback = null;
        $customer = Customer::find($id);

        if (! $customer) {
            $this->notify('error', 'El cliente ya no existe.');

            return;
        }

        $customer->update(['is_active' => ! $customer->is_active]);
        $this->notify('success', $customer->is_active
            ? "Cliente «{$customer->name}» activado."
            : "Cliente «{$customer->name}» desactivado.");
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'commercial_name', 'identification', 'activity_code',
            'email', 'phone', 'address', 'branch_id', 'credit_cutoff_day', 'notes',
        ]);
        $this->identification_type = '01';
        $this->payment_condition = Customer::PAYMENT_CASH;
        $this->credit_limit = 0;
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        $query = Customer::query()->with('branch:id,name');

        if ($this->search !== '') {
            $termino = '%' . $this->search . '%';
            $query->where(fn ($q) => $q
                ->where('name', 'like', $termino)
                ->orWhere('commercial_name', 'like', $termino)
                ->orWhere('identification', 'like', $termino)
                ->orWhere('email', 'like', $termino)
                ->orWhere('phone', 'like', $termino));
        }

        if ($this->filterCondition !== '') {
            $query->where('payment_condition', $this->filterCondition);
        }

        return view('livewire.customers.customer-index', [
            'customers' => $query->orderBy('name')->paginate(15),
            'branches'  => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['title' => 'Clientes']);
    }
}
