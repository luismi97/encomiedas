<?php

namespace App\Livewire\Invoices;

use App\Livewire\Concerns\ConsultaHacienda;
use App\Rules\DeLaEmpresa;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\PackageType;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Rate;
use App\Models\ShippingRoute;
use App\Models\Tax;
use App\Services\CajaService;
use App\Services\CreditoService;
use App\Services\RegistroDeGuia;
use App\Services\Tarifario;
use Illuminate\Validation\Rule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class InvoiceForm extends Component
{
    use ConsultaHacienda;

    public ?Invoice $invoice = null;

    /**
     * Ruta predefinida. Es un atajo: rellena las dos sedes de un solo toque.
     *
     * No reemplaza a los selects —un envío suelto hacia una sede sin ruta tiene
     * que poder facturarse igual—, pero queda guardada en la guía, porque de
     * ella sale la fecha que se le prometió al cliente.
     */
    public $shipping_route_id = null;

    public $pickup_branch_id = null;
    public $delivery_branch_id = null;

    /** Cliente registrado. Al elegirlo se precargan sus datos de contacto. */
    public $sender_customer_id = null;

    /*
     | Búsqueda de clientes. Antes se listaba la tabla entera en dos <select>:
     | con 5.000 clientes eran 10.000 <option> y 1,2 MB de HTML que Livewire
     | reenvía en cada interacción.
     */
    public string $senderSearch = '';
    public string $recipientSearch = '';
    public $recipient_customer_id = null;

    public string $shipment_type = 'package';
    public $declared_value = 0;

    /*
     | Entrega a domicilio: se cobra aparte y necesita dirección exacta, porque
     | el destino ya no es una sucursal donde el cliente pasa a retirar.
     */
    public bool $home_delivery = false;
    public string $delivery_address = '';
    public $home_delivery_fee = 0;

    /**
     * Clave que autoriza el descuento.
     *
     * No se guarda con la guía: solo se comprueba al validar. Lo que queda
     * registrado es QUIÉN autorizó, que es lo que sirve para auditar.
     */
    public string $discountCode = '';

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

    public string $sender_name = '';
    public string $sender_phone = '';
    public string $sender_identification = '';
    public string $sender_identification_type = '01';
    public string $sender_email = '';

    public string $recipient_name = '';
    public string $recipient_phone = '';
    public string $recipient_identification_type = '01';
    public string $recipient_identification = '';
    public string $recipient_email = '';

    public string $notes = '';
    public $discount_amount = 0;
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

    /** A quién se factura: destinatario, remitente u otra persona. */
    public string $bill_to = Invoice::BILL_TO_RECIPIENT;
    public string $billing_name = '';
    public string $billing_identification_type = '01';
    public string $billing_identification = '';
    public string $billing_email = '';

    /** CodigoActividadReceptor: de a quién se factura, sea quien sea. */
    public string $billing_activity_code = '';
    public $assigned_to = null;

    /** @var array<int,array<string,mixed>> */
    public array $items = [];

    /** @var array<int> id de impuestos seleccionados */
    public array $selectedTaxes = [];

    public function mount(?Invoice $invoice = null): void
    {
        $this->items = [
            ['package_type_id' => PackageType::porDefecto()?->id, 'quantity' => 1, 'size' => 'M', 'weight' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'description' => '', 'price' => ''],
        ];

        if ($invoice && $invoice->exists) {
            $this->invoice = $invoice;
            $this->shipping_route_id = $invoice->shipping_route_id;
            $this->pickup_branch_id = $invoice->pickup_branch_id;
            $this->delivery_branch_id = $invoice->delivery_branch_id;
            $this->sender_name = $invoice->sender_name;
            $this->sender_phone = (string) $invoice->sender_phone;
            $this->sender_identification = (string) $invoice->sender_identification;
            $this->sender_identification_type = $invoice->sender_identification_type ?: '01';
            $this->sender_email = (string) $invoice->sender_email;
            $this->bill_to = $invoice->bill_to ?: Invoice::BILL_TO_RECIPIENT;
            $this->billing_name = (string) $invoice->billing_name;
            $this->billing_identification_type = $invoice->billing_identification_type ?: '01';
            $this->billing_identification = (string) $invoice->billing_identification;
            $this->billing_email = (string) $invoice->billing_email;
            $this->billing_activity_code = (string) $invoice->billing_activity_code;
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
            $this->home_delivery = (bool) $invoice->home_delivery;
            $this->delivery_address = (string) $invoice->delivery_address;
            $this->home_delivery_fee = (float) $invoice->home_delivery_fee;
            $this->assigned_to = $invoice->assigned_to;
            $this->items = $invoice->items->map(fn ($i) => [
                'package_type_id' => $i->package_type_id,
                'quantity' => $i->cantidad(),
                'size' => $i->size,
                'weight' => $i->weight,
                'length_cm' => $i->length_cm,
                'width_cm' => $i->width_cm,
                'height_cm' => $i->height_cm,
                'description' => $i->description,
                // El formulario trabaja con el precio por bulto.
                'price' => $i->precioUnitario(),
            ])->toArray();
            $this->selectedTaxes = $invoice->taxes->pluck('tax_id')->filter()->toArray();
        } else {
            $this->selectedTaxes = Tax::where('is_default', true)->pluck('id')->toArray();

            // El dependiente recibe en el mostrador de su sede base.
            if (auth()->user()->isDependiente()) {
                $this->pickup_branch_id = auth()->user()->branch_id;
            }
        }
    }

    /**
     * La cédula se digita con guiones ("1-1234-0567") con toda naturalidad:
     * se limpia ANTES de validar para no rechazar algo que sí es válido.
     */
    private function normalizeIdentification(): void
    {
        $this->recipient_identification = preg_replace('/\D/', '', (string) $this->recipient_identification);
        $this->billing_identification = preg_replace('/\D/', '', (string) $this->billing_identification);

        // La del remitente es texto libre (pasaportes, etc.) salvo cuando se
        // le factura: ahí manda el formato de Hacienda.
        if ($this->facturaA(Invoice::BILL_TO_SENDER)) {
            $this->sender_identification = preg_replace('/\D/', '', (string) $this->sender_identification);
        }
    }

    /*
     | Al terminar de digitar una cédula se consulta Hacienda y se completan el
     | nombre y el tipo, se facture o no a esa persona. Las actividades solo
     | importan si es a quien se factura.
     */
    public function updatedSenderIdentification(): void
    {
        $this->autocompletarDesdeHacienda(Invoice::BILL_TO_SENDER);
    }

    public function updatedRecipientIdentification(): void
    {
        $this->autocompletarDesdeHacienda(Invoice::BILL_TO_RECIPIENT);
    }

    public function updatedBillingIdentification(): void
    {
        $this->autocompletarDesdeHacienda(Invoice::BILL_TO_OTHER);
    }

    public function updatedBillTo(): void
    {
        $this->actividadesHacienda = [];
        $this->avisoHacienda = null;
    }

    /** Botón «Buscar en Hacienda» del bloque de factura. */
    public function buscarEnHacienda(): void
    {
        $this->autocompletarDesdeHacienda($this->bill_to);
    }

    private function autocompletarDesdeHacienda(string $parte): void
    {
        [$campoNombre, $campoTipo, $campoId] = match ($parte) {
            Invoice::BILL_TO_SENDER => ['sender_name', 'sender_identification_type', 'sender_identification'],
            Invoice::BILL_TO_OTHER  => ['billing_name', 'billing_identification_type', 'billing_identification'],
            default                 => ['recipient_name', 'recipient_identification_type', 'recipient_identification'],
        };

        $esQuienSeFactura = $this->facturaA($parte);

        // Consultar a alguien a quien no se factura no puede borrar las
        // actividades ni el aviso de quien sí.
        $actividades = $this->actividadesHacienda;
        $aviso = $this->avisoHacienda;

        $resultado = $this->consultarContribuyente($this->{$campoId});

        if (! $esQuienSeFactura) {
            $this->actividadesHacienda = $actividades;
            $this->avisoHacienda = $aviso;
        }

        if (($resultado['name'] ?? null) === null) {
            return;
        }

        $this->{$campoId} = preg_replace('/\D/', '', (string) $this->{$campoId});
        $this->{$campoTipo} = $resultado['id_type'] ?? $this->{$campoTipo};
        $this->ponerNombreDeHacienda($campoNombre, $resultado['name'], $this->{$campoTipo});

        if ($esQuienSeFactura
            && ! in_array($this->billing_activity_code, array_column($this->actividadesHacienda, 'code'), true)) {
            $this->billing_activity_code = (string) $this->actividadPrincipal();
        }
    }

    /**
     * Apagar el toggle solo quita el error: la cédula se conserva, porque
     * también sirve para verificar a quien retira el paquete.
     */
    public function updatedWantsInvoice(bool $value): void
    {
        if (!$value) {
            $this->resetErrorBag([
                'recipient_identification', 'recipient_identification_type',
                'sender_identification', 'sender_identification_type',
                'billing_name', 'billing_identification', 'billing_identification_type',
            ]);
        }
    }

    /** ¿Se emite factura y va a nombre de esta parte? */
    private function facturaA(string $parte): bool
    {
        return $this->wantsInvoice && $this->bill_to === $parte;
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
        $this->sender_identification_type = (string) ($cliente->identification_type ?: '01');
        $this->sender_email = (string) $cliente->email;

        if ($cliente->activity_code && $this->bill_to === Invoice::BILL_TO_SENDER) {
            $this->billing_activity_code = (string) $cliente->activity_code;
        }

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

            if ($cliente->activity_code && $this->bill_to === Invoice::BILL_TO_RECIPIENT) {
                $this->billing_activity_code = (string) $cliente->activity_code;
            }
        }
    }

    /**
     * Elegir la ruta pone las dos sedes.
     *
     * Vaciarla no las borra: quien deselecciona la ruta suele querer corregir
     * una sola de las dos sedes, y dejarle el formulario en blanco lo obligaría
     * a empezar de nuevo.
     */
    public function updatedShippingRouteId($value): void
    {
        if (! $value || ! $ruta = ShippingRoute::find($value)) {
            return;
        }

        $this->pickup_branch_id = $ruta->origin_branch_id;
        $this->delivery_branch_id = $ruta->destination_branch_id;

        $this->cotizar(app(Tarifario::class));
    }

    /**
     * La ruta guardada tiene que decir la verdad.
     *
     * Si después de elegirla alguien corrige una sede a mano, la guía ya no va
     * por esa ruta: se suelta, y con ella la fecha prometida. Guardar una ruta
     * que no coincide con las sedes sería prometer un plazo de otro viaje.
     */
    private function soltarRutaSiYaNoCoincide(): void
    {
        if (! $this->shipping_route_id || ! $ruta = ShippingRoute::find($this->shipping_route_id)) {
            return;
        }

        $coincide = (int) $ruta->origin_branch_id === (int) $this->pickup_branch_id
            && (int) $ruta->destination_branch_id === (int) $this->delivery_branch_id;

        if (! $coincide) {
            $this->shipping_route_id = null;
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
        if (in_array($campo, ['pickup_branch_id', 'delivery_branch_id'], true)) {
            $this->soltarRutaSiYaNoCoincide();
        }

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

            $pesoTotal += $cotizacion['peso_facturable'] * self::cantidadDe($item);

            if ($cotizacion['precio'] === null) {
                $sinTarifa = true;
                continue;
            }

            // La tarifa es por bulto: la línea cobra precio × cantidad.
            $precioTotal += $cotizacion['precio'] * self::cantidadDe($item);

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
        $this->items[] = ['package_type_id' => PackageType::porDefecto()?->id, 'quantity' => 1, 'size' => 'M', 'weight' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'description' => '', 'price' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function getSubtotalProperty(): float
    {
        return collect($this->items)->sum(fn ($i) => (float) ($i['price'] ?? 0) * self::cantidadDe($i));
    }

    /** Lo que se digitó en Cantidad, con 1 si quedó vacío o en cero. */
    private static function cantidadDe(array $item): int
    {
        return max(1, (int) ($item['quantity'] ?? 1));
    }

    /**
     * Seguro sobre el valor declarado.
     *
     * El cliente declara cuánto vale lo que manda y se le cobra un porcentaje
     * por responder de ello. Antes ese riesgo se asumía gratis.
     */
    public function getInsuranceFeeProperty(): float
    {
        return Invoice::calcularSeguro((float) $this->declared_value);
    }

    /** Lo que se cobra por llevarlo a la puerta, si aplica. */
    public function getHomeDeliveryFeeAmountProperty(): float
    {
        return $this->home_delivery ? max(0.0, (float) $this->home_delivery_fee) : 0.0;
    }

    /**
     * Base gravable: los bultos más los cargos, menos el descuento.
     *
     * El seguro y el domicilio entran ANTES del impuesto: son parte del
     * servicio que se factura, no un agregado posterior.
     */
    public function getTaxableBaseProperty(): float
    {
        return round(
            $this->subtotal + $this->insuranceFee + $this->homeDeliveryFeeAmount - (float) $this->discount_amount,
            2
        );
    }

    public function getTaxTotalProperty(): float
    {
        $percent = Tax::whereIn('id', $this->selectedTaxes)->sum('percent');

        return round($this->taxableBase * $percent / 100, 2);
    }

    public function getTotalProperty(): float
    {
        return round($this->taxableBase + $this->taxTotal, 2);
    }

    protected function rules(): array
    {
        return [
            'shipping_route_id' => ['nullable', DeLaEmpresa::en('shipping_routes')],
            'pickup_branch_id' => array_filter([
                'required',
                DeLaEmpresa::en('branches'),
                // El dependiente solo recibe en las sedes que atiende.
                auth()->user()->isDependiente() ? Rule::in(auth()->user()->sedesIds()) : null,
            ]),
            // Origen y destino pueden ser la misma sede: es un paquete que
            // alguien deja y otro recoge ahí mismo. No viaja en ningún cierre
            // (ver Invoice::esMismaSede) y su código queda SJ-SJ-00001.
            'delivery_branch_id' => ['required', DeLaEmpresa::en('branches')],
            'sender_name' => 'required|string|max:150',
            'sender_phone' => 'nullable|string|max:30',
            'sender_identification' => $this->facturaA(Invoice::BILL_TO_SENDER)
                ? ['required', 'regex:/^\d{9,12}$/']
                : ['nullable', 'string', 'max:20'],
            'sender_identification_type' => $this->facturaA(Invoice::BILL_TO_SENDER) ? 'required|in:01,02,03,04' : 'nullable',
            'sender_email' => 'nullable|email',
            'bill_to' => ['required', Rule::in(array_keys(Invoice::BILL_TO))],
            'billing_name' => $this->facturaA(Invoice::BILL_TO_OTHER) ? 'required|string|max:150' : 'nullable',
            'billing_identification' => $this->facturaA(Invoice::BILL_TO_OTHER)
                ? ['required', 'regex:/^\d{9,12}$/']
                : 'nullable',
            'billing_identification_type' => $this->facturaA(Invoice::BILL_TO_OTHER) ? 'required|in:01,02,03,04' : 'nullable',
            'billing_email' => 'nullable|email',
            'billing_activity_code' => ['nullable', 'regex:/^(?:\d{6}|\d{4}\.\d)$/'],
            'sender_customer_id' => ['nullable', DeLaEmpresa::en('customers')],
            'recipient_customer_id' => ['nullable', DeLaEmpresa::en('customers')],
            'shipment_type' => ['nullable', Rule::in(array_keys(Rate::SHIPMENT_TYPES))],
            'declared_value' => 'nullable|numeric|min:0',
            'recipient_name' => 'required|string|max:150',
            'recipient_phone' => 'nullable|string|max:30',
            'recipient_identification' => $this->facturaA(Invoice::BILL_TO_RECIPIENT)
                ? ['required', 'regex:/^\d{9,12}$/']
                : ['nullable', 'string', 'max:20'],
            'recipient_identification_type' => $this->facturaA(Invoice::BILL_TO_RECIPIENT) ? 'required|in:01,02,03,04' : 'nullable',
            'recipient_email' => 'nullable|email',
            'assigned_to' => ['nullable', DeLaEmpresa::en('users')],
            'discount_amount' => 'nullable|numeric|min:0',
            'home_delivery_fee' => 'nullable|numeric|min:0',
            // Sin dirección exacta, «a domicilio» es una promesa sin destino.
            'delivery_address' => $this->home_delivery ? 'required|string|max:255' : 'nullable|string|max:255',
            'payment_method' => 'required|in:' . implode(',', array_keys(Invoice::PAYMENT_METHODS)),
            'cobro' => 'required|in:' . self::COBRO_PREPAID . ',' . self::COBRO_COLLECT . ',' . self::COBRO_CREDIT,
            'items' => 'required|array|min:1',
            'items.*.package_type_id' => ['required', DeLaEmpresa::en('package_types')],
            'items.*.quantity' => 'required|integer|min:1|max:999',
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
            'pickup_branch_id.in' => 'Solo podés recibir encomiendas en las sedes que tenés asignadas.',
            'recipient_identification.required' => 'Para emitir Factura Electrónica hace falta la identificación del receptor. '
                . 'Sin ella el comprobante debe ser Tiquete Electrónico.',
            'recipient_identification.regex' => 'La identificación son de 9 a 12 dígitos, sin guiones ni espacios.',
            'sender_identification.required' => 'Para facturarle al remitente hace falta su identificación.',
            'sender_identification.regex' => 'La identificación son de 9 a 12 dígitos, sin guiones ni espacios.',
            'billing_name.required' => 'Indicá a nombre de quién va la factura.',
            'billing_identification.required' => 'Para emitir Factura Electrónica hace falta la identificación de a quién se factura.',
            'billing_identification.regex' => 'La identificación son de 9 a 12 dígitos, sin guiones ni espacios.',
            'billing_activity_code.regex' => 'El código de actividad son 6 dígitos (ej. 492300) o 4 con decimal (ej. 4923.0).',
            'items.*.package_type_id.required' => 'Elegí qué tipo de bulto es (paquete, caja, sobre...).',
            'items.*.package_type_id.exists' => 'Ese tipo de bulto ya no está disponible.',
            'items.*.weight.numeric' => 'El peso debe ser un número en kilogramos (ej. 12.5).',
            'items.*.weight.min' => 'El peso no puede ser negativo.',
            'items.*.description.max' => 'La descripción del paquete no puede pasar de 255 caracteres.',
            'items.*.price.required' => 'El precio del paquete es obligatorio.',
            'items.*.price.numeric' => 'El precio debe ser un número.',
            'items.*.price.min' => 'El precio no puede ser negativo.',
            'items.*.quantity.min' => 'La cantidad mínima es 1.',
            'items.*.quantity.required' => 'Indicá cuántos bultos van en esta línea.',
            'delivery_address.required' => 'Para entregar a domicilio hace falta la dirección exacta.',
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

    /**
     * Un descuento necesita autorización.
     *
     * Sin esto cualquier cajero rebajaba lo que quisiera sin dejar rastro. Con
     * la clave configurada hay que digitarla, y la guía guarda quién autorizó
     * —que es lo que sirve para auditar después—.
     */
    private function validarDescuento(): void
    {
        $descuento = (float) $this->discount_amount;

        if ($descuento <= 0 || $this->descuentoSinCambios()) {
            return;
        }

        $empresa = CompanySetting::instance();

        if (! $empresa->exigeClaveParaDescuento()) {
            return;
        }

        if (! $empresa->claveDeDescuentoValida($this->discountCode)) {
            throw ValidationException::withMessages([
                'discountCode' => 'La clave de autorización no es correcta. '
                    . 'Sin ella no se puede aplicar un descuento.',
            ]);
        }
    }

    /**
     * Un cobro de contado exige una caja abierta.
     *
     * Antes la guía se guardaba igual y solo se dejaba un aviso en una
     * propiedad del componente —que el redirect posterior descartaba, así que
     * nadie lo veía nunca—. El resultado era plata cobrada en el mostrador que
     * no figuraba en ningún arqueo: exactamente lo que este módulo existe para
     * impedir.
     *
     * Solo aplica al contado pagado en origen. Un «por cobrar» se cobra en
     * destino y una guía a crédito no se cobra: ninguno mueve esta gaveta.
     */
    private function validarCajaAbierta(): void
    {
        if ($this->cobro !== self::COBRO_PREPAID) {
            return;
        }

        // Quien no cobra no abre caja: su guía de contado queda esperando el
        // pago en caja (ver save()) y el cajero la cobra en su turno.
        if (! auth()->user()->puedeCobrar()) {
            return;
        }

        // Al editar una guía ya cobrada no se vuelve a cobrar: exigir caja
        // abierta bloquearía corregir un teléfono mal escrito.
        if ($this->invoice?->exists && ! $this->invoice->esCredito() && ! $this->invoice->esPorCobrar()) {
            return;
        }

        // Propio y no cualquiera de la sede: cobrar contra el turno de un
        // compañero le deja a él un faltante por dinero que nunca manejó.
        $sesion = app(CajaService::class)
            ->sesionPropiaAbierta(auth()->user(), $this->pickup_branch_id);

        if ($sesion) {
            return;
        }

        throw ValidationException::withMessages([
            'cobro' => 'No tenés una caja abierta en esta sede, así que el cobro no entraría a ningún arqueo. '
                . 'Abrí tu caja y volvé a guardar. Si el flete no se cobra acá, marcá «Por cobrar» o «A crédito».',
        ]);
    }

    /**
     * Al editar, un descuento que ya estaba autorizado no se vuelve a pedir:
     * corregir un teléfono no puede exigir la clave de un descuento de ayer.
     */
    private function descuentoSinCambios(): bool
    {
        return $this->invoice?->exists
            && abs((float) $this->discount_amount - (float) $this->invoice->discount_amount) < 0.01;
    }

    public function save(): void
    {
        $this->normalizeItems();
        $this->normalizeIdentification();
        $data = $this->validate();
        $this->validarCredito();
        $this->validarDescuento();
        $this->validarCajaAbierta();

        // Cómo estaba antes de guardar, para ajustar lo que cuelga de la guía.
        $antes = $this->invoice?->exists ? [
            'total'      => round((float) $this->invoice->total, 2),
            'estado'     => $this->invoice->credit_statement_id,
            'facturaA'   => $this->firmaDeFacturacion($this->invoice),
        ] : null;
        $avisos = [];

        DB::transaction(function () use ($data, $antes, &$avisos) {
            $invoice = app(RegistroDeGuia::class)->guardar($this->invoice, [
                'pickup_branch_id' => $data['pickup_branch_id'],
                'delivery_branch_id' => $data['delivery_branch_id'],
                'shipping_route_id' => $data['shipping_route_id'] ?: null,
                'sender_name' => $data['sender_name'],
                'sender_phone' => $data['sender_phone'],
                'sender_identification' => $data['sender_identification'],
                'sender_identification_type' => filled($data['sender_identification']) ? $this->sender_identification_type : null,
                'sender_email' => $data['sender_email'] ?: null,
                'bill_to' => $this->bill_to,
                // Los datos del tercero solo se guardan si de verdad se le factura.
                'billing_name' => $this->bill_to === Invoice::BILL_TO_OTHER ? ($data['billing_name'] ?: null) : null,
                'billing_identification_type' => $this->bill_to === Invoice::BILL_TO_OTHER && filled($data['billing_identification']) ? $this->billing_identification_type : null,
                'billing_identification' => $this->bill_to === Invoice::BILL_TO_OTHER ? ($data['billing_identification'] ?: null) : null,
                'billing_email' => $this->bill_to === Invoice::BILL_TO_OTHER ? ($data['billing_email'] ?: null) : null,
                'billing_activity_code' => $this->wantsInvoice
                    ? (\App\Services\Hacienda\Catalogs::normalizeActivityCode($data['billing_activity_code'] ?? '') ?: null)
                    : null,
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => $data['recipient_phone'],
                'bill_type' => $this->wantsInvoice ? Invoice::BILL_INVOICE : Invoice::BILL_TICKET,
                'sender_customer_id' => $data['sender_customer_id'],
                'recipient_customer_id' => $data['recipient_customer_id'],
                'shipment_type' => $data['shipment_type'] ?: null,
                'declared_value' => $data['declared_value'] ?: 0,
                // Ya calculado: si mañana cambia el porcentaje, esta guía debe
                // seguir diciendo lo que se cobró.
                'insurance_fee' => $this->insuranceFee,
                'home_delivery' => $this->home_delivery,
                'delivery_address' => $this->home_delivery ? ($data['delivery_address'] ?: null) : null,
                'home_delivery_fee' => $this->homeDeliveryFeeAmount,
                'discount_authorized_by' => (float) $this->discount_amount <= 0 ? null
                    : ($this->descuentoSinCambios() ? $this->invoice->discount_authorized_by : auth()->id()),
                // Se guarda aunque sea tiquete: el tiquete no la manda a Hacienda
                // (ver receptorIdentificado) y la entrega la usa para verificar.
                'recipient_identification_type' => filled($data['recipient_identification']) ? $this->recipient_identification_type : null,
                'recipient_identification' => $data['recipient_identification'] ?: null,
                'recipient_email' => $data['recipient_email'],
                'notes' => $this->notes,
                'discount_amount' => $data['discount_amount'] ?: 0,
                'payment_method' => $data['payment_method'],
                'assigned_to' => $data['assigned_to'],
                'subtotal' => $this->subtotal,
                'tax_total' => $this->taxTotal,
                'total' => $this->total,
                'cobro' => $this->cobro,
                'items' => $data['items'],
                'tax_ids' => $this->selectedTaxes,
            ], auth()->user());

            // Flash y no una propiedad del componente: el redirect de abajo la
            // descartaría y el aviso no llegaría a verse nunca.
            $aviso = match (true) {
                $invoice->awaiting_cashier => 'Guía de contado PENDIENTE DE PAGO: el cliente tiene que pasar por caja. '
                    . 'El paquete no puede salir hasta que se cobre.',
                $this->cobro === self::COBRO_COLLECT => 'Guía POR COBRAR: no entra al arqueo de esta caja. '
                    . 'Se cobra en destino al momento de la entrega.',
                $this->cobro === self::COBRO_CREDIT => 'Guía a crédito: no entra al arqueo. '
                    . 'Suma al saldo del cliente y se factura en el próximo corte.',
                default => null,
            };

            if ($antes) {
                $avisos = $this->ajustarLoQueDependeDeLaGuia($invoice, $antes);
            }

            if ($aviso || $avisos) {
                session()->flash('info', trim($aviso . ' ' . implode(' ', $avisos)));
            }

            $this->invoice = $invoice;
        });

        session()->flash('success', 'Factura guardada correctamente.');
        $this->redirect(route('invoices.show', $this->invoice), navigate: false);
    }

    /** Lo que define a quién y cómo se factura: si cambia, el comprobante también. */
    private function firmaDeFacturacion(Invoice $guia): string
    {
        return json_encode([
            $guia->bill_type,
            $guia->receptorIdentificado(),
            $guia->receptorIdentificado() ? $guia->receptorDeFactura() : null,
        ]);
    }

    /**
     * Al editar una guía ya existente, pone al día lo que se calculó con los
     * datos viejos y avisa de lo que no se puede tocar solo.
     *
     * Es lo que permite que un administrador corrija CUALQUIER campo: sin
     * esto, cambiar el monto de una guía ya cortada dejaba el estado de cuenta
     * cobrando el monto viejo, y cambiar a quién se factura dejaba el
     * comprobante pendiente con la cédula anterior.
     *
     * @param  array{total:float, estado:?int, facturaA:string}  $antes
     * @return list<string>  avisos para quien guardó
     */
    private function ajustarLoQueDependeDeLaGuia(Invoice $guia, array $antes): array
    {
        $avisos = [];
        $guia->refresh();
        $cambioElTotal = abs(round((float) $guia->total, 2) - $antes['total']) >= 0.01;

        // Estado de cuenta: si la guía dejó de ser a crédito sale del corte;
        // si cambió el monto, el corte se rehace con el nuevo.
        if ($antes['estado'] && $estado = \App\Models\CreditStatement::find($antes['estado'])) {
            if (! $guia->esCredito()) {
                $guia->forceFill(['credit_statement_id' => null])->save();
                $avisos[] = "La guía salió del estado de cuenta {$estado->code} porque ya no es a crédito.";
            }

            if ($cambioElTotal || ! $guia->esCredito()) {
                app(CreditoService::class)->recalcularEstado($estado);
                $avisos[] = "El estado de cuenta {$estado->code} quedó en ₡" . number_format((float) $estado->total, 2) . '.';
            }
        }

        // Comprobante electrónico: el que no llegó a Hacienda se rehace con los
        // datos nuevos; el que ya llegó no se puede cambiar.
        if ($comprobante = $guia->electronicInvoice()->first()) {
            $cambioLaFacturacion = $this->firmaDeFacturacion($guia) !== $antes['facturaA'];
            $editable = in_array($comprobante->status, [
                \App\Models\ElectronicInvoice::STATUS_PENDING,
                \App\Models\ElectronicInvoice::STATUS_REJECTED,
            ], true);

            if ($editable && $cambioLaFacturacion) {
                app(\App\Services\Hacienda\ElectronicBillingService::class)->rehacerPorCambioDeReceptor($comprobante);
                $avisos[] = 'Su comprobante pendiente se rehízo con los datos de facturación nuevos.';
            } elseif (! $editable && ($cambioElTotal || $cambioLaFacturacion)) {
                $avisos[] = 'Ojo: el comprobante electrónico ya está en Hacienda («' . $comprobante->statusLabel() . '») '
                    . 'y no cambió. Si hay que corregir lo declarado, emití una nota de crédito o débito desde la guía.';
            }
        }

        // Caja: lo cobrado en un turno anterior no se mueve solo (en el turno
        // abierto, RegistroDeGuia ya actualizó el monto).
        $cobro = \App\Models\CashMovement::where('invoice_id', $guia->id)
            ->where('type', \App\Models\CashMovement::TYPE_SALE)
            ->first();

        if ($cambioElTotal && $cobro && abs((float) $cobro->amount - (float) $guia->total) >= 0.01) {
            $avisos[] = 'El cobro de esta guía ya está en un arqueo con el monto anterior: '
                . 'si hay diferencia, registrala como entrada o salida de caja.';
        }

        return $avisos;
    }

    /**
     * Clientes que coinciden con lo escrito.
     *
     * Con tope y a partir de dos caracteres: sin eso, la primera tecla traería
     * media tabla y el buscador sería tan pesado como el select que reemplaza.
     */
    private function buscarClientes(string $termino)
    {
        $termino = trim($termino);

        if (mb_strlen($termino) < 2) {
            return null;
        }

        return Customer::active()
            ->buscar($termino)
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'identification']);
    }

    /**
     * Las rutas del desplegable, en el orden en que sirven.
     *
     * Primero las que salen de la sede de quien atiende —que son las suyas— y
     * dentro de eso las más usadas: en un mostrador, la ruta correcta casi
     * siempre es la de ayer.
     */
    private function rutasDisponibles()
    {
        $miSede = auth()->user()?->branch_id;

        return ShippingRoute::active()
            ->with(['originBranch', 'destinationBranch'])
            ->withCount('invoices')
            ->when($miSede, fn ($q) => $q->orderByRaw('origin_branch_id = ? desc', [$miSede]))
            ->orderByDesc('invoices_count')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        return view('livewire.invoices.invoice-form', [
            'branches' => $branches,
            // Desde dónde puede recibir: el dependiente, solo sus sedes.
            'sedesDeOrigen' => auth()->user()->isDependiente()
                ? $branches->whereIn('id', auth()->user()->sedesIds())->values()
                : $branches,
            'rutas' => $this->rutasDisponibles(),
            'rutaElegida' => $this->shipping_route_id ? ShippingRoute::find($this->shipping_route_id) : null,
            'taxes' => Tax::where('is_active', true)->orderBy('name')->get(),
            'repartidores' => User::where('role', User::ROLE_REPARTIDOR)->where('is_active', true)->orderBy('name')->get(),
            'remitenteElegido' => $this->sender_customer_id ? Customer::find($this->sender_customer_id) : null,
            'destinatarioElegido' => $this->recipient_customer_id ? Customer::find($this->recipient_customer_id) : null,
            'resultadosRemitente' => $this->sender_customer_id ? null : $this->buscarClientes($this->senderSearch),
            'resultadosDestinatario' => $this->recipient_customer_id ? null : $this->buscarClientes($this->recipientSearch),
            'remitenteEsDeCredito' => $this->sender_customer_id
                ? (bool) Customer::find($this->sender_customer_id)?->isCredit()
                : false,
            'tiposDeBulto' => PackageType::active()->get(),
            'empresa' => CompanySetting::instance(),
        ])->layout('layouts.app', ['title' => $this->invoice ? 'Editar guía' : 'Nueva guía']);
    }
}
