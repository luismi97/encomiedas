<?php

namespace App\Livewire\Customers;

use App\Support\CompanyContext;
use App\Rules\DeLaEmpresa;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Livewire\Concerns\ConsultaHacienda;
use App\Livewire\Concerns\ScrollInfinito;
use App\Services\Hacienda\Catalogs;
use App\Services\Hacienda\ExoneracionLookup;
use Livewire\Component;

class CustomerIndex extends Component
{
    use ScrollInfinito;
    use ConsultaHacienda;

    public string $search = '';
    public string $filterCondition = '';

    public bool $showForm = false;
    public $editingId = null;

    public string $name = '';
    public string $commercial_name = '';
    public string $identification_type = '01';
    public string $identification = '';
    public string $activity_code = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public $branch_id = null;
    public string $payment_condition = Customer::PAYMENT_CASH;
    public $credit_limit = 0;
    public $credit_cutoff_day = null;
    public string $notes = '';
    public bool $is_active = true;

    // Exoneración del IVA (nodo Exoneracion de la Factura Electrónica).
    public bool $tax_exempt = false;
    public string $exemption_number = '';
    public string $exemption_document_type = '';
    public string $exemption_document_type_other = '';
    public string $exemption_institution = '';
    public string $exemption_institution_other = '';
    public $exemption_article = null;
    public $exemption_inciso = null;
    public string $exemption_issued_at = '';
    public string $exemption_expires_at = '';
    public $exemption_rate = 13;
    /** @var array<int,string> CABYS que cubre, según EXONET. */
    public array $exemption_cabys = [];
    public ?string $avisoExoneracion = null;

    /** Livewire re-renderiza el componente, no el layout: el aviso vive aquí. */
    public ?string $feedback = null;
    public string $feedbackType = 'success';

    public function updatedSearch(): void
    {
        $this->reiniciarScroll();
    }

    public function updatedFilterCondition(): void
    {
        $this->reiniciarScroll();
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'commercial_name' => 'nullable|string|max:150',
            'identification_type' => ['nullable', Rule::in(array_keys(Customer::IDENTIFICATION_TYPES))],
            'identification' => [
                'nullable', 'regex:/^\d{9,12}$/',
                Rule::unique('customers', 'identification')
                    ->where(fn ($q) => $q->where('company_id', CompanyContext::id()))
                    ->ignore($this->editingId),
            ],
            'activity_code' => ['nullable', 'regex:/^(?:\d{6}|\d{4}\.\d)$/'],
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'branch_id' => ['nullable', DeLaEmpresa::en('branches')],
            'payment_condition' => ['required', Rule::in(array_keys(Customer::PAYMENT_CONDITIONS))],
            'credit_limit' => 'nullable|numeric|min:0',
            'credit_cutoff_day' => 'nullable|integer|min:1|max:31',
            'notes' => 'nullable|string|max:1000',
            'tax_exempt' => 'boolean',
            'exemption_number' => $this->tax_exempt ? 'required|string|min:3|max:40' : 'nullable',
            'exemption_document_type' => $this->tax_exempt
                ? ['required', Rule::in(array_keys(Catalogs::EXEMPTION_DOCUMENT_TYPES))]
                : 'nullable',
            'exemption_document_type_other' => $this->tax_exempt && $this->exemption_document_type === '99'
                ? 'required|string|min:5|max:100' : 'nullable',
            'exemption_institution' => $this->tax_exempt
                ? ['required', Rule::in(array_keys(Catalogs::EXEMPTION_INSTITUTIONS))]
                : 'nullable',
            'exemption_institution_other' => $this->tax_exempt && $this->exemption_institution === '99'
                ? 'required|string|min:5|max:160' : 'nullable',
            'exemption_article' => 'nullable|integer|min:0|max:999999',
            'exemption_inciso' => 'nullable|integer|min:0|max:999999',
            'exemption_issued_at' => $this->tax_exempt ? 'required|date' : 'nullable',
            'exemption_expires_at' => 'nullable|date|after_or_equal:exemption_issued_at',
            'exemption_rate' => $this->tax_exempt ? 'required|numeric|min:0.01|max:13' : 'nullable',
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
            'exemption_number.required' => 'Sin el número de autorización no hay exoneración que declarar.',
            'exemption_document_type.required' => 'Elegí el tipo de documento de la exoneración.',
            'exemption_document_type_other.required' => 'Con «Otros» hay que describir el documento (mínimo 5 letras).',
            'exemption_institution.required' => 'Elegí la institución que emitió la exoneración.',
            'exemption_institution_other.required' => 'Con «Otros» hay que escribir el nombre de la institución.',
            'exemption_issued_at.required' => 'La fecha de emisión de la exoneración va en la factura.',
            'exemption_expires_at.after_or_equal' => 'El vencimiento no puede ser antes de la emisión.',
            'exemption_rate.max' => 'No se puede exonerar más que el 13 % del IVA.',
        ];
    }

    /**
     * Al terminar de digitar la cédula se consulta Hacienda: nombre oficial,
     * tipo de identificación y actividades económicas.
     */
    public function updatedIdentification(): void
    {
        // Se digita con guiones con toda naturalidad; la regla exige solo dígitos.
        $this->identification = preg_replace('/\D/', '', $this->identification);

        $resultado = $this->consultarContribuyente($this->identification);

        if (($resultado['name'] ?? null) === null) {
            return;
        }

        $this->identification_type = $resultado['id_type'] ?? $this->identification_type;
        $this->ponerNombreDeHacienda('name', $resultado['name'], $this->identification_type);

        if (! in_array($this->activity_code, array_column($this->actividadesHacienda, 'code'), true)) {
            $this->activity_code = (string) $this->actividadPrincipal();
        }
    }

    /**
     * Trae la exoneración de EXONET por su número: es contra lo que Hacienda
     * valida la factura, así que mejor copiarlo que digitarlo.
     */
    public function consultarExoneracion(ExoneracionLookup $lookup): void
    {
        $this->avisoExoneracion = null;
        $this->exemption_number = strtoupper(trim($this->exemption_number));

        if ($this->exemption_number === '') {
            $this->addError('exemption_number', 'Digitá el número de autorización (ej. AL-00012345-25).');

            return;
        }

        $r = $lookup->find($this->exemption_number);

        if ($r['status'] === ExoneracionLookup::NOT_FOUND) {
            $this->avisoExoneracion = 'Hacienda no tiene registrada esa exoneración. Revisá el número.';

            return;
        }

        if ($r['status'] !== ExoneracionLookup::FOUND) {
            $this->avisoExoneracion = 'No se pudo consultar Hacienda. Completá los datos a mano.';

            return;
        }

        $this->exemption_number = $r['numero'];
        $this->exemption_document_type = (string) ($r['tipo'] ?? $this->exemption_document_type);
        $this->exemption_institution = (string) ($r['institucion'] ?? $this->exemption_institution);
        $this->exemption_issued_at = (string) ($r['fecha_emision'] ?? $this->exemption_issued_at);
        $this->exemption_expires_at = (string) ($r['vence'] ?? '');
        $this->exemption_rate = $r['tarifa'] > 0 ? $r['tarifa'] : $this->exemption_rate;
        $this->exemption_cabys = $r['cabys'];

        // La exoneración es de una cédula: si el cliente no tiene, se la pone;
        // si tiene otra, se avisa, porque la factura saldría rechazada.
        if (blank($this->identification) && filled($r['identificacion'])) {
            $this->identification = $r['identificacion'];
            $this->updatedIdentification();
        } elseif (filled($r['identificacion']) && $r['identificacion'] !== $this->identification) {
            $this->avisoExoneracion = "Ojo: esta exoneración es de la identificación {$r['identificacion']}, "
                . 'no de la de este cliente. Hacienda rechazaría las facturas.';

            return;
        }

        if ($this->exemption_expires_at !== '' && \Carbon\Carbon::parse($this->exemption_expires_at)->endOfDay()->isPast()) {
            $this->avisoExoneracion = 'Esta exoneración ya venció el '
                . \Carbon\Carbon::parse($this->exemption_expires_at)->format('d/m/Y') . '.';
        }
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
        $this->actividadesHacienda = [];
        $this->avisoHacienda = null;
        $this->nombresDeHacienda = [];
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
        $this->tax_exempt = (bool) $customer->tax_exempt;
        $this->exemption_number = (string) $customer->exemption_number;
        $this->exemption_document_type = (string) $customer->exemption_document_type;
        $this->exemption_document_type_other = (string) $customer->exemption_document_type_other;
        $this->exemption_institution = (string) $customer->exemption_institution;
        $this->exemption_institution_other = (string) $customer->exemption_institution_other;
        $this->exemption_article = $customer->exemption_article;
        $this->exemption_inciso = $customer->exemption_inciso;
        $this->exemption_issued_at = (string) $customer->exemption_issued_at?->toDateString();
        $this->exemption_expires_at = (string) $customer->exemption_expires_at?->toDateString();
        $this->exemption_rate = $customer->exemption_rate !== null ? (float) $customer->exemption_rate : 13;
        $this->exemption_cabys = $customer->exemption_cabys ?: [];
        $this->avisoExoneracion = null;
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

        // La exoneración se declara a nombre de una cédula, en Factura
        // Electrónica: sin identificación no hay a quién declarársela.
        if ($this->tax_exempt && blank($this->identification)) {
            throw ValidationException::withMessages([
                'identification' => 'Un cliente exonerado necesita identificación: la exoneración se declara '
                    . 'en Factura Electrónica a su nombre.',
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
                ], $this->datosDeExoneracion())
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

    /** Desmarcada, no queda una exoneración a medias que alguien reactive sin revisar. */
    private function datosDeExoneracion(): array
    {
        $exento = $this->tax_exempt;
        $o = fn ($valor) => $exento && filled($valor) ? $valor : null;

        return [
            'tax_exempt' => $exento,
            'exemption_number' => $o(strtoupper(trim($this->exemption_number))),
            'exemption_document_type' => $o($this->exemption_document_type),
            'exemption_document_type_other' => $this->exemption_document_type === '99' ? $o($this->exemption_document_type_other) : null,
            'exemption_institution' => $o($this->exemption_institution),
            'exemption_institution_other' => $this->exemption_institution === '99' ? $o($this->exemption_institution_other) : null,
            'exemption_article' => $o($this->exemption_article),
            'exemption_inciso' => $o($this->exemption_inciso),
            'exemption_issued_at' => $o($this->exemption_issued_at),
            'exemption_expires_at' => $o($this->exemption_expires_at),
            'exemption_rate' => $exento ? (float) $this->exemption_rate : null,
            'exemption_cabys' => $exento && $this->exemption_cabys ? array_values($this->exemption_cabys) : null,
        ];
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'commercial_name', 'identification', 'activity_code',
            'email', 'phone', 'address', 'branch_id', 'credit_cutoff_day', 'notes',
        ]);
        $this->identification_type = '01';
        $this->actividadesHacienda = [];
        $this->avisoHacienda = null;
        $this->nombresDeHacienda = [];
        $this->payment_condition = Customer::PAYMENT_CASH;
        $this->credit_limit = 0;
        $this->is_active = true;
        $this->reset([
            'tax_exempt', 'exemption_number', 'exemption_document_type', 'exemption_document_type_other',
            'exemption_institution', 'exemption_institution_other', 'exemption_article', 'exemption_inciso',
            'exemption_issued_at', 'exemption_expires_at', 'exemption_rate', 'exemption_cabys', 'avisoExoneracion',
        ]);
        $this->resetErrorBag();
    }

    public function render()
    {
        $query = Customer::query()->with('branch:id,name');

        $query->buscar($this->search);

        if ($this->filterCondition === 'exempt') {
            $query->where('tax_exempt', true);
        } elseif ($this->filterCondition !== '') {
            $query->where('payment_condition', $this->filterCondition);
        }

        $tanda = $this->tanda($query->orderBy('name'));

        return view('livewire.customers.customer-index', [
            'customers' => $tanda['items'],
            'scroll'    => $tanda,
            'branches'  => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['title' => 'Clientes']);
    }
}
