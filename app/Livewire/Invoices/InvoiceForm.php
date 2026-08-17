<?php

namespace App\Livewire\Invoices;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Rate;
use App\Models\Tax;
use App\Services\CajaService;
use App\Services\Tarifario;
use Illuminate\Validation\Rule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class InvoiceForm extends Component
{
    public ?Invoice $invoice = null;

    public ?int $pickup_branch_id = null;
    public ?int $delivery_branch_id = null;

    /** Cliente registrado. Al elegirlo se precargan sus datos de contacto. */
    public ?int $sender_customer_id = null;
    public ?int $recipient_customer_id = null;

    public string $shipment_type = 'package';
    public float $declared_value = 0;

    /** Cotización del tarifario para la ruta y el peso actuales. */
    public array $quote = [];

    /** Aviso cuando se va a cobrar de contado sin caja abierta. */
    public ?string $cajaAviso = null;

    public string $sender_name = '';
    public string $sender_phone = '';
    public string $sender_identification = '';

    public string $recipient_name = '';
    public string $recipient_phone = '';
    public string $recipient_identification_type = '01';
    public string $recipient_identification = '';
    public string $recipient_email = '';

    public string $notes = '';
    public float $discount_amount = 0;
    public string $payment_method = 'cash';

    /**
     * Factura electrónica (con receptor identificado) o tiquete. Es una
     * elección explícita: deducirla de si venía la cédula emitía FE sin querer.
     */
    public bool $wantsInvoice = false;
    public ?int $assigned_to = null;

    /** @var array<int,array<string,mixed>> */
    public array $items = [];

    /** @var array<int> id de impuestos seleccionados */
    public array $selectedTaxes = [];

    public function mount(?Invoice $invoice = null): void
    {
        $this->items = [
            ['package_code' => '', 'size' => 'M', 'weight' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'description' => '', 'price' => ''],
        ];

        if ($invoice && $invoice->exists) {
            $this->invoice = $invoice;
            $this->pickup_branch_id = $invoice->pickup_branch_id;
            $this->delivery_branch_id = $invoice->delivery_branch_id;
            $this->sender_name = $invoice->sender_name;
            $this->sender_phone = (string) $invoice->sender_phone;
            $this->sender_identification = (string) $invoice->sender_identification;
            $this->recipient_name = $invoice->recipient_name;
            $this->recipient_phone = (string) $invoice->recipient_phone;
            $this->recipient_identification_type = $invoice->recipient_identification_type ?: '01';
            $this->recipient_identification = (string) $invoice->recipient_identification;
            $this->recipient_email = (string) $invoice->recipient_email;
            $this->notes = (string) $invoice->notes;
            $this->discount_amount = (float) $invoice->discount_amount;
            $this->payment_method = $invoice->payment_method ?: 'cash';
            $this->wantsInvoice = $invoice->wantsInvoice();
            $this->sender_customer_id = $invoice->sender_customer_id;
            $this->recipient_customer_id = $invoice->recipient_customer_id;
            $this->shipment_type = (string) ($invoice->shipment_type ?: 'package');
            $this->declared_value = (float) $invoice->declared_value;
            $this->assigned_to = $invoice->assigned_to;
            $this->items = $invoice->items->map(fn ($i) => [
                'package_code' => $i->package_code,
                'size' => $i->size,
                'weight' => $i->weight,
                'length_cm' => $i->length_cm,
                'width_cm' => $i->width_cm,
                'height_cm' => $i->height_cm,
                'description' => $i->description,
                'price' => (float) $i->price,
            ])->toArray();
            $this->selectedTaxes = $invoice->taxes->pluck('tax_id')->filter()->toArray();
        } else {
            $this->selectedTaxes = Tax::where('is_default', true)->pluck('id')->toArray();
        }
    }

    /**
     * La cédula se digita con guiones ("1-1234-0567") con toda naturalidad:
     * se limpia ANTES de validar para no rechazar algo que sí es válido.
     */
    private function normalizeIdentification(): void
    {
        if ($this->wantsInvoice) {
            $this->recipient_identification = preg_replace('/\D/', '', (string) $this->recipient_identification);
        }
    }

    /** Apagar el toggle limpia lo que solo aplica a la factura. */
    public function updatedWantsInvoice(bool $value): void
    {
        if (!$value) {
            $this->recipient_identification = '';
            $this->resetErrorBag(['recipient_identification', 'recipient_identification_type']);
        }
    }

    /**
     * Elegir un cliente registrado copia sus datos a la guía. Se copian y no se
     * referencian porque la guía es un documento: si el cliente cambia de
     * teléfono el año que viene, la guía vieja debe seguir diciendo lo que
     * decía cuando se emitió.
     */
    public function updatedSenderCustomerId($value): void
    {
        if (! $cliente = Customer::find($value)) {
            return;
        }

        $this->sender_name = $cliente->name;
        $this->sender_phone = (string) $cliente->phone;
        $this->sender_identification = (string) $cliente->identification;

        if ($cliente->branch_id && ! $this->pickup_branch_id) {
            $this->pickup_branch_id = $cliente->branch_id;
        }
    }

    public function updatedRecipientCustomerId($value): void
    {
        if (! $cliente = Customer::find($value)) {
            return;
        }

        $this->recipient_name = $cliente->name;
        $this->recipient_phone = (string) $cliente->phone;
        $this->recipient_email = (string) $cliente->email;

        // Con cédula del receptor la guía puede salir como Factura Electrónica.
        if ($cliente->puedeFacturaElectronica()) {
            $this->recipient_identification = (string) $cliente->identification;
            $this->recipient_identification_type = (string) $cliente->identification_type;
            $this->wantsInvoice = true;
        }
    }

    /**
     * Cotiza con el tarifario y propone el precio.
     *
     * Propone y no impone: el cajero puede pisarlo, porque hay casos que ninguna
     * tabla cubre (cliente frecuente, paquete frágil, acuerdo puntual).
     */
    public function cotizar(Tarifario $tarifario): void
    {
        $origen  = $this->pickup_branch_id ? Branch::find($this->pickup_branch_id) : null;
        $destino = $this->delivery_branch_id ? Branch::find($this->delivery_branch_id) : null;

        $pesoTotal = 0.0;
        $precioTotal = 0.0;
        $sinTarifa = false;

        // Cada paquete cotiza por su cuenta: dos cajas de 3 kg no pagan lo
        // mismo que una de 6, porque cada una entra en su propio rango.
        foreach ($this->items as $i => $item) {
            // Los campos del formulario llegan como texto: sin castear, el
            // servicio recibe string donde espera ?float.
            $dimension = fn (string $clave) => blank($item[$clave] ?? null) ? null : (float) $item[$clave];

            $cotizacion = $tarifario->cotizar(
                $origen,
                $destino,
                (float) ($item['weight'] ?? 0),
                $dimension('length_cm'),
                $dimension('width_cm'),
                $dimension('height_cm'),
                $this->shipment_type ?: null
            );

            $pesoTotal += $cotizacion['peso_facturable'];

            if ($cotizacion['precio'] === null) {
                $sinTarifa = true;
                continue;
            }

            $precioTotal += $cotizacion['precio'];
            $this->items[$i]['price'] = $cotizacion['precio'];
        }

        $this->quote = [
            'peso_total'   => round($pesoTotal, 2),
            'precio_total' => round($precioTotal, 2),
            'sin_tarifa'   => $sinTarifa,
        ];
    }

    public function addItem(): void
    {
        $this->items[] = ['package_code' => '', 'size' => 'M', 'weight' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'description' => '', 'price' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function getSubtotalProperty(): float
    {
        return collect($this->items)->sum(fn ($i) => (float) ($i['price'] ?? 0));
    }

    public function getTaxTotalProperty(): float
    {
        $base = $this->subtotal - (float) $this->discount_amount;
        $percent = Tax::whereIn('id', $this->selectedTaxes)->sum('percent');
        return round($base * $percent / 100, 2);
    }

    public function getTotalProperty(): float
    {
        return round($this->subtotal - (float) $this->discount_amount + $this->taxTotal, 2);
    }

    protected function rules(): array
    {
        return [
            'pickup_branch_id' => 'required|exists:branches,id',
            'delivery_branch_id' => 'required|exists:branches,id',
            'sender_name' => 'required|string|max:150',
            'sender_phone' => 'nullable|string|max:30',
            'sender_identification' => 'nullable|string|max:20',
            'sender_customer_id' => 'nullable|exists:customers,id',
            'recipient_customer_id' => 'nullable|exists:customers,id',
            'shipment_type' => ['nullable', Rule::in(array_keys(Rate::SHIPMENT_TYPES))],
            'declared_value' => 'nullable|numeric|min:0',
            'recipient_name' => 'required|string|max:150',
            'recipient_phone' => 'nullable|string|max:30',
            'recipient_identification' => $this->wantsInvoice
                ? ['required', 'regex:/^\d{9,12}$/']
                : ['nullable', 'string', 'max:20'],
            'recipient_identification_type' => $this->wantsInvoice ? 'required|in:01,02,03,04' : 'nullable',
            'recipient_email' => 'nullable|email',
            'assigned_to' => 'nullable|exists:users,id',
            'discount_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:' . implode(',', array_keys(Invoice::PAYMENT_METHODS)),
            'items' => 'required|array|min:1',
            'items.*.package_code' => 'required|string|max:100',
            'items.*.size' => 'nullable|string|max:20',
            'items.*.weight' => 'nullable|numeric|min:0|max:999999.99',
            'items.*.length_cm' => 'nullable|numeric|min:0|max:999999.99',
            'items.*.width_cm' => 'nullable|numeric|min:0|max:999999.99',
            'items.*.height_cm' => 'nullable|numeric|min:0|max:999999.99',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.price' => 'required|numeric|min:0',
        ];
    }

    protected function messages(): array
    {
        return [
            'recipient_identification.required' => 'Para emitir Factura Electrónica hace falta la identificación del receptor. '
                . 'Sin ella el comprobante debe ser Tiquete Electrónico.',
            'recipient_identification.regex' => 'La identificación son de 9 a 12 dígitos, sin guiones ni espacios.',
            'items.*.package_code.required' => 'El código del paquete es obligatorio.',
            'items.*.weight.numeric' => 'El peso debe ser un número en kilogramos (ej. 12.5).',
            'items.*.weight.min' => 'El peso no puede ser negativo.',
            'items.*.description.max' => 'La descripción del paquete no puede pasar de 255 caracteres.',
            'items.*.price.required' => 'El precio del paquete es obligatorio.',
            'items.*.price.numeric' => 'El precio debe ser un número.',
            'items.*.price.min' => 'El precio no puede ser negativo.',
        ];
    }

    /**
     * Los campos vacios del formulario llegan como '' y no como null: con
     * 'nullable|numeric' un peso en blanco fallaria por "no es un numero".
     */
    private function normalizeItems(): void
    {
        foreach ($this->items as $i => $item) {
            foreach (['size', 'weight', 'length_cm', 'width_cm', 'height_cm', 'description'] as $key) {
                if (!array_key_exists($key, $item) || $item[$key] === '') {
                    $this->items[$i][$key] = null;
                }
            }
        }
    }

    public function save(): void
    {
        $this->normalizeItems();
        $this->normalizeIdentification();
        $data = $this->validate();

        DB::transaction(function () use ($data) {
            $invoice = $this->invoice ?: new Invoice();
            $invoice->fill([
                'pickup_branch_id' => $data['pickup_branch_id'],
                'delivery_branch_id' => $data['delivery_branch_id'],
                'sender_name' => $data['sender_name'],
                'sender_phone' => $data['sender_phone'],
                'sender_identification' => $data['sender_identification'],
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => $data['recipient_phone'],
                'bill_type' => $this->wantsInvoice ? Invoice::BILL_INVOICE : Invoice::BILL_TICKET,
                'sender_customer_id' => $data['sender_customer_id'],
                'recipient_customer_id' => $data['recipient_customer_id'],
                'shipment_type' => $data['shipment_type'] ?: null,
                'declared_value' => $data['declared_value'] ?: 0,
                'recipient_identification_type' => $this->wantsInvoice ? $this->recipient_identification_type : null,
                'recipient_identification' => $this->wantsInvoice ? $data['recipient_identification'] : null,
                'recipient_email' => $data['recipient_email'],
                'notes' => $this->notes,
                'discount_amount' => $data['discount_amount'] ?: 0,
                'payment_method' => $data['payment_method'],
                'assigned_to' => $data['assigned_to'],
                'subtotal' => $this->subtotal,
                'tax_total' => $this->taxTotal,
                'total' => $this->total,
            ]);
            if (!$invoice->exists) {
                $invoice->created_by = auth()->id();
                $invoice->status = Invoice::STATUS_PENDING;
            }
            $invoice->save();

            $invoice->items()->delete();
            foreach ($data['items'] as $item) {
                $invoice->items()->create([
                    'package_code' => $item['package_code'],
                    'size' => $item['size'],
                    'weight' => $item['weight'],
                    'length_cm' => $item['length_cm'],
                    'width_cm' => $item['width_cm'],
                    'height_cm' => $item['height_cm'],
                    'description' => $item['description'],
                    'price' => $item['price'],
                ]);
            }

            $invoice->taxes()->delete();
            foreach (Tax::whereIn('id', $this->selectedTaxes)->get() as $tax) {
                $base = $this->subtotal - (float) $this->discount_amount;
                $invoice->taxes()->create([
                    'tax_id' => $tax->id,
                    'name' => $tax->name,
                    'percent' => $tax->percent,
                    'hacienda_code' => $tax->hacienda_code,
                    'amount' => round($base * $tax->percent / 100, 2),
                ]);
            }

            // El cobro entra al turno abierto. Si no hay caja abierta la guía
            // igual se guarda —ya se recibió el paquete— pero queda sin
            // registrar en caja y el formulario lo avisa.
            $movimiento = app(CajaService::class)->registrarCobro($invoice, auth()->user());

            $this->cajaAviso = $movimiento === null
                ? 'La guía se guardó, pero NO quedó registrada en caja porque no hay un turno abierto en esta sede. '
                    . 'Abrí la caja y volvé a guardar para que el cobro entre al arqueo.'
                : null;

            $this->invoice = $invoice;
        });

        session()->flash('success', 'Factura guardada correctamente.');
        $this->redirect(route('invoices.show', $this->invoice), navigate: false);
    }

    public function render()
    {
        return view('livewire.invoices.invoice-form', [
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'taxes' => Tax::where('is_active', true)->orderBy('name')->get(),
            'repartidores' => User::where('role', User::ROLE_REPARTIDOR)->where('is_active', true)->orderBy('name')->get(),
            'clientes' => Customer::active()->orderBy('name')->get(['id', 'name', 'identification']),
        ])->layout('layouts.app', ['title' => $this->invoice ? 'Editar guía' : 'Nueva guía']);
    }
}
