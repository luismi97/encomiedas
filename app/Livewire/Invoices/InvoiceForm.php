<?php

namespace App\Livewire\Invoices;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\PackageType;
use App\Models\Customer;
use App\Models\Rate;
use App\Models\Tax;
use App\Services\CajaService;
use App\Services\CreditoService;
use App\Services\Tarifario;
use Illuminate\Validation\Rule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    /**
     * Último precio que propuso el tarifario para cada renglón.
     *
     * Sirve para distinguir un precio puesto por el sistema de uno digitado por
     * el cajero: al recotizar solo se pisa el primero. Sin esto, corregir el
     * peso borraría un acuerdo puntual sin avisar.
     */
    public array $preciosSugeridos = [];

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

    /*
     | Qué pasa con la plata de esta guía. Una sola decisión, porque en el
     | mostrador son excluyentes:
     |
     |   prepaid  el remitente paga ahora   -> entra a la caja de origen
     |   collect  paga quien retira         -> entra a la caja de destino
     |   credit   va a la cuenta del cliente -> no entra a ninguna caja
     |
     | Antes no existía: toda guía se guardaba como contado pagado, así que un
     | cliente con convenio enviaba y su saldo nunca se movía.
     */
    public const COBRO_PREPAID = 'prepaid';
    public const COBRO_COLLECT = 'collect';
    public const COBRO_CREDIT  = 'credit';

    public string $cobro = self::COBRO_PREPAID;

    /** Aviso de saldo del remitente, cuando es cliente de crédito. */
    public ?string $creditoAviso = null;

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
            ['package_type_id' => PackageType::porDefecto()?->id, 'size' => 'M', 'weight' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'description' => '', 'price' => ''],
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
            $this->cobro = match (true) {
                $invoice->esCredito()   => self::COBRO_CREDIT,
                $invoice->esPorCobrar() => self::COBRO_COLLECT,
                default                 => self::COBRO_PREPAID,
            };
            $this->wantsInvoice = $invoice->wantsInvoice();
            $this->sender_customer_id = $invoice->sender_customer_id;
            $this->recipient_customer_id = $invoice->recipient_customer_id;
            $this->shipment_type = (string) ($invoice->shipment_type ?: 'package');
            $this->declared_value = (float) $invoice->declared_value;
            $this->assigned_to = $invoice->assigned_to;
            $this->items = $invoice->items->map(fn ($i) => [
                'package_type_id' => $i->package_type_id,
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

        $this->ajustarCobroAlRemitente($cliente);
    }

    /**
     * Un cliente con convenio envía a crédito salvo que se diga lo contrario.
     *
     * Es el punto que faltaba: sin esto la guía salía como contado pagado y el
     * saldo del cliente nunca se movía, por más que tuviera límite y día de
     * corte configurados.
     */
    private function ajustarCobroAlRemitente(?Customer $cliente): void
    {
        if (! $cliente?->isCredit()) {
            // Deja de ofrecerse el crédito: quedaría una guía a nombre de nadie.
            if ($this->cobro === self::COBRO_CREDIT) {
                $this->cobro = self::COBRO_PREPAID;
            }

            $this->creditoAviso = null;

            return;
        }

        $this->cobro = self::COBRO_CREDIT;
        $this->mostrarSaldoDelRemitente($cliente);
    }

    /** Cuánto debe y cuánto le queda, que es lo que el cajero necesita ver. */
    private function mostrarSaldoDelRemitente(Customer $cliente): void
    {
        $credito = app(CreditoService::class);
        $saldo = $credito->saldoTotal($cliente);

        if ((float) $cliente->credit_limit <= 0) {
            $this->creditoAviso = "{$cliente->name} tiene ₡" . number_format($saldo, 2)
                . ' de saldo. No tiene límite configurado.';

            return;
        }

        $this->creditoAviso = "{$cliente->name} debe ₡" . number_format($saldo, 2)
            . ' de un límite de ₡' . number_format((float) $cliente->credit_limit, 2)
            . '. Disponible: ₡' . number_format(max(0, $credito->disponible($cliente)), 2) . '.';
    }

    /** Al cambiar de modo a mano, refresca el aviso de saldo. */
    public function updatedCobro(): void
    {
        $this->resetErrorBag('cobro');
        $this->creditoAviso = null;

        if ($this->cobro !== self::COBRO_CREDIT) {
            return;
        }

        $cliente = $this->sender_customer_id ? Customer::find($this->sender_customer_id) : null;

        if ($cliente?->isCredit()) {
            $this->mostrarSaldoDelRemitente($cliente);
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
     * Recotiza sola cuando cambia algo que afecta el precio.
     *
     * Antes había que presionar «Calcular con el tarifario»: quien creaba una
     * tarifa y se iba a facturar veía el precio en blanco y concluía que el
     * tarifario no servía.
     */
    public function updated(string $campo): void
    {
        $afectaElPrecio = in_array($campo, ['pickup_branch_id', 'delivery_branch_id', 'shipment_type'], true)
            || preg_match('/^items\.\d+\.(weight|length_cm|width_cm|height_cm)$/', $campo);

        if ($afectaElPrecio) {
            $this->cotizar(app(Tarifario::class));
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

            // Solo se pisa lo que puso el propio tarifario: un precio digitado
            // a mano manda sobre la tabla.
            $actual = $this->items[$i]['price'] ?? '';
            $loPusoElSistema = blank($actual)
                || (isset($this->preciosSugeridos[$i])
                    && abs((float) $actual - (float) $this->preciosSugeridos[$i]) < 0.01);

            if ($loPusoElSistema) {
                $this->items[$i]['price'] = $cotizacion['precio'];
                $this->preciosSugeridos[$i] = $cotizacion['precio'];
            }
        }

        $this->quote = [
            'peso_total'   => round($pesoTotal, 2),
            'precio_total' => round($precioTotal, 2),
            'sin_tarifa'   => $sinTarifa,
        ];
    }

    public function addItem(): void
    {
        $this->items[] = ['package_type_id' => PackageType::porDefecto()?->id, 'size' => 'M', 'weight' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'description' => '', 'price' => ''];
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
            // Una encomienda es un traslado entre sedes: origen y destino
            // iguales no es un envío, y además rompe el código guía, que se
            // arma con los dos prefijos (SJ-SJ-00001 no significa nada).
            'delivery_branch_id' => 'required|exists:branches,id|different:pickup_branch_id',
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
            'cobro' => 'required|in:' . self::COBRO_PREPAID . ',' . self::COBRO_COLLECT . ',' . self::COBRO_CREDIT,
            'items' => 'required|array|min:1',
            'items.*.package_type_id' => 'required|exists:package_types,id',
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
            'delivery_branch_id.different' => 'La sede de destino tiene que ser distinta de la de origen: '
                . 'una encomienda es un traslado entre sedes.',
            'items.*.package_type_id.required' => 'Elegí qué tipo de bulto es (paquete, caja, sobre...).',
            'items.*.package_type_id.exists' => 'Ese tipo de bulto ya no está disponible.',
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

    /**
     * Una guía a crédito exige remitente con convenio y cupo disponible.
     *
     * El control de límite ya existía en CreditoService y nadie lo llamaba: se
     * podía pasar del tope sin que nada avisara.
     */
    private function validarCredito(): void
    {
        if ($this->cobro !== self::COBRO_CREDIT) {
            return;
        }

        $cliente = $this->sender_customer_id ? Customer::find($this->sender_customer_id) : null;

        if (! $cliente) {
            throw ValidationException::withMessages([
                'cobro' => 'Para dejar la guía a crédito hay que elegir al remitente entre los clientes '
                    . 'registrados: el saldo se le carga a alguien.',
            ]);
        }

        $credito = app(CreditoService::class);

        // Al editar, el monto viejo ya está contado en el saldo: se compara
        // solo lo que la guía agrega.
        $yaContado = $this->invoice?->esCredito() && ! $this->invoice->fueCortada()
            ? (float) $this->invoice->total
            : 0.0;

        if ($motivo = $credito->bloqueoPorLimite($cliente, $this->total - $yaContado)) {
            throw ValidationException::withMessages(['cobro' => $motivo]);
        }
    }

    public function save(): void
    {
        $this->normalizeItems();
        $this->normalizeIdentification();
        $data = $this->validate();
        $this->validarCredito();

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
            $invoice->sale_condition = $this->cobro === self::COBRO_CREDIT
                ? Invoice::SALE_CREDIT
                : Invoice::SALE_CASH;

            $invoice->payment_timing = $this->cobro === self::COBRO_COLLECT
                ? Invoice::TIMING_COLLECT
                : Invoice::TIMING_PREPAID;

            if (!$invoice->exists) {
                $invoice->created_by = auth()->id();
                $invoice->status = Invoice::STATUS_PENDING;
            }
            $invoice->save();

            $invoice->items()->delete();
            foreach ($data['items'] as $item) {
                $invoice->items()->create([
                    'package_type_id' => $item['package_type_id'],
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

            // A la caja de origen solo entra lo que se paga aquí y ahora. Un
            // «por cobrar» se cobra en destino al entregar, y una guía a
            // crédito no se cobra: suma al saldo del cliente.
            $this->cajaAviso = match ($this->cobro) {
                self::COBRO_COLLECT => 'Guía POR COBRAR: no entra al arqueo de esta caja. '
                    . 'Se cobra en destino al momento de la entrega.',
                self::COBRO_CREDIT => 'Guía a crédito: no entra al arqueo. '
                    . 'Suma al saldo del cliente y se factura en el próximo corte.',
                default => app(CajaService::class)->registrarCobro($invoice, auth()->user()) === null
                    ? 'La guía se guardó, pero NO quedó registrada en caja porque no hay un turno abierto en esta sede. '
                        . 'Abrí la caja y volvé a guardar para que el cobro entre al arqueo.'
                    : null,
            };

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
            'remitenteEsDeCredito' => $this->sender_customer_id
                ? (bool) Customer::find($this->sender_customer_id)?->isCredit()
                : false,
            'tiposDeBulto' => PackageType::active()->get(),
        ])->layout('layouts.app', ['title' => $this->invoice ? 'Editar guía' : 'Nueva guía']);
    }
}
