<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PackageType;
use App\Models\Rate;
use App\Models\ShippingRoute;
use App\Models\Tax;
use App\Models\User;
use App\Services\CajaService;
use App\Services\CreditoService;
use App\Services\RegistroDeGuia;
use App\Support\CompanyContext;
use App\Support\ModoOffline;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Guías sin conexión. Copia del POS offline de retailpos, con tres piezas:
 *
 *  - page(): la pantalla para recibir encomiendas sin red. El service worker
 *            la guarda y la sirve cuando el servidor no responde.
 *  - data(): el «snapshot» que el navegador guarda para trabajar a ciegas:
 *            sedes, rutas, tarifas, impuestos, clientes de crédito.
 *  - sync(): sube las guías hechas sin conexión. Es IDEMPOTENTE: cada una
 *            trae un client_uuid con índice único, así que reintentos, dos
 *            pestañas o dos equipos no duplican nada.
 *
 * Una guía sincronizada queda igual que una hecha en línea porque pasa por el
 * mismo RegistroDeGuia: mismo código guía, misma bitácora, mismo cobro en caja.
 *
 * Contrato de sync(), un resultado por guía. La diferencia entre `failed` y
 * `error` es de diseño:
 *
 *  - synced     se creó                                   sale de la cola
 *  - duplicate  ya existía con ese client_uuid            sale de la cola
 *  - failed     NUNCA va a entrar (no cuadra, bulto o     sale de la cola y
 *               sede que no existen)                      queda a la vista
 *  - error      algo que alguien puede corregir (caja     SE QUEDA en la cola
 *               cerrada, límite de crédito, impuesto      y se reintenta
 *               borrado)
 *
 * Marcar `failed` algo corregible saca la guía de la cola para siempre: al
 * agregar un rechazo nuevo, preguntarse si un administrador puede arreglarlo.
 */
class GuiasOfflineController extends Controller
{
    private const MAX_POR_TANDA = 50;

    public function page()
    {
        if (! ModoOffline::habilitado()) {
            return redirect()->route('invoices.create');
        }

        return view('offline.guias');
    }

    /** Snapshot para trabajar sin conexión. */
    public function data(Request $request)
    {
        if (! ModoOffline::habilitado()) {
            return response()->json(['enabled' => false]);
        }

        // La pantalla offline lo pide antes de subir la cola solo para tomar
        // un token CSRF fresco: el de la página guardada puede estar vencido.
        if ($request->boolean('light')) {
            return response()->json(['enabled' => true, 'csrf' => csrf_token()]);
        }

        $usuario = $request->user();
        $empresa = CompanySetting::instance();
        $credito = app(CreditoService::class);
        $caja = app(CajaService::class)->sesionPropiaAbierta($usuario, $usuario->branch_id)?->register;

        return response()->json([
            'enabled'      => true,
            'generated_at' => now()->toIso8601String(),
            'csrf'         => csrf_token(),

            'usuario' => [
                'id'            => $usuario->id,
                'name'          => $usuario->name,
                'branch_id'     => $usuario->branch_id,
                'puede_cobrar'  => $usuario->puedeCobrar(),
                // Solo para avisar: sin conexión no se puede saber si la caja
                // sigue abierta. Se comprueba de verdad al sincronizar.
                'caja_abierta'  => $caja !== null,
            ],

            'empresa' => [
                'nombre'   => $empresa->commercial_name ?: $empresa->name,
                'cedula'   => $empresa->identification_number,
                'telefono' => $empresa->phone,
                // Para imprimir en el comprobante provisional dónde seguir la
                // encomienda. «__REF__» se reemplaza por el número provisional.
                'rastreo'  => ($slug = CompanyContext::actual()?->slug)
                    ? route('rastreo.empresa', ['empresa' => $slug, 'code' => '__REF__'])
                    : route('rastreo.ver', ['code' => '__REF__']),
                'rastreo_corto' => route('rastreo.buscar'),
            ],

            'papel' => $this->papel($usuario, $caja),

            'sedes' => Branch::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'prefix'])
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'prefix' => $b->prefix]),

            'rutas' => ShippingRoute::active()->orderBy('name')
                ->get(['id', 'name', 'origin_branch_id', 'destination_branch_id'])
                ->map(fn (ShippingRoute $r) => [
                    'id' => $r->id, 'name' => $r->name,
                    'origin' => $r->origin_branch_id, 'destination' => $r->destination_branch_id,
                ]),

            'tipos_bulto' => PackageType::active()->get(['id', 'name', 'is_fragile'])
                ->map(fn (PackageType $t) => ['id' => $t->id, 'name' => $t->name, 'fragil' => (bool) $t->is_fragile]),

            'tipos_envio' => Rate::SHIPMENT_TYPES,

            // El tarifario completo: se recotiza en el navegador con la misma
            // regla que Tarifario::buscar() (ver tarifa() en la pantalla).
            'tarifas' => Rate::active()->get()->map(fn (Rate $r) => [
                'id'          => $r->id,
                'origin'      => $r->origin_branch_id,
                'destination' => $r->destination_branch_id,
                'shipment'    => $r->shipment_type,
                'min'         => (float) $r->min_weight,
                'max'         => $r->max_weight === null ? null : (float) $r->max_weight,
                'price'       => (float) $r->price,
                'extra_kg'    => (float) $r->price_per_extra_kg,
            ]),
            'divisor_volumetrico' => (float) config('encomiendas.volumetric_divisor', 5000),
            'porcentaje_seguro'   => $empresa->porcentajeDeSeguro(),

            // Los que el formulario en línea marca por defecto.
            'impuestos' => Tax::where('is_active', true)->where('is_default', true)->get(['id', 'name', 'percent'])
                ->map(fn (Tax $t) => ['id' => $t->id, 'name' => $t->name, 'percent' => (float) $t->percent]),

            'medios_pago' => Invoice::PAYMENT_METHODS,

            // Solo los de crédito: una guía a crédito exige remitente
            // registrado. El saldo es el de este momento; al sincronizar se
            // vuelve a comprobar contra el de verdad.
            'clientes_credito' => Customer::active()->credit()->orderBy('name')->get()
                ->map(fn (Customer $c) => [
                    'id'                  => $c->id,
                    'name'                => $c->name,
                    'phone'               => $c->phone,
                    'identification'      => $c->identification,
                    'identification_type' => $c->identification_type,
                    'email'               => $c->email,
                    'limite'              => (float) $c->credit_limit,
                    'saldo'               => $credito->saldoTotal($c),
                ]),

            'clave_descuento' => $empresa->verificadorDeDescuento(),
        ]);
    }

    public function sync(Request $request)
    {
        if (! ModoOffline::habilitado()) {
            return response()->json(['enabled' => false], 403);
        }

        $request->validate([
            'guias'               => 'required|array|min:1|max:' . self::MAX_POR_TANDA,
            'guias.*.client_uuid' => 'required|uuid',
        ]);

        // validate() devuelve solo lo que tiene regla: cada guía se valida
        // entera más abajo, así que se lee el crudo.
        $resultados = collect($request->input('guias'))
            ->map(fn (array $guia) => $this->sincronizarUna($guia, $request->user()))
            ->all();

        return response()->json(['enabled' => true, 'results' => $resultados]);
    }

    /**
     * Una guía, con su propio resultado: una que no cuadra no debe arrastrar a
     * las demás de la misma tanda.
     */
    private function sincronizarUna(array $guia, User $sincroniza): array
    {
        $uuid = (string) $guia['client_uuid'];

        if ($existente = Invoice::where('client_uuid', $uuid)->first()) {
            return $this->resultado($uuid, 'duplicate', guia: $existente);
        }

        $validador = Validator::make($guia, [
            'offline_reference'     => 'nullable|string|max:40',
            'created_at'            => 'required|date',
            'sold_by'               => 'nullable|integer',
            'pickup_branch_id'      => 'required|integer',
            'delivery_branch_id'    => 'required|integer|different:pickup_branch_id',
            'shipping_route_id'     => 'nullable|integer',
            'shipment_type'         => ['nullable', Rule::in(array_keys(Rate::SHIPMENT_TYPES))],
            'sender_name'           => 'required|string|max:150',
            'sender_phone'          => 'nullable|string|max:30',
            'sender_identification' => 'nullable|string|max:20',
            'sender_customer_id'    => 'nullable|integer',
            'recipient_name'        => 'required|string|max:150',
            'recipient_phone'       => 'nullable|string|max:30',
            'recipient_identification' => 'nullable|string|max:20',
            'recipient_email'       => 'nullable|email',
            'declared_value'        => 'nullable|numeric|min:0',
            'insurance_fee'         => 'nullable|numeric|min:0',
            'home_delivery'         => 'boolean',
            'delivery_address'      => 'nullable|required_if:home_delivery,true|string|max:255',
            'home_delivery_fee'     => 'nullable|numeric|min:0',
            'discount_amount'       => 'nullable|numeric|min:0',
            'notes'                 => 'nullable|string|max:1000',
            'cobro'                 => ['required', Rule::in([RegistroDeGuia::COBRO_PREPAID, RegistroDeGuia::COBRO_COLLECT, RegistroDeGuia::COBRO_CREDIT])],
            'payment_method'        => ['required', Rule::in(array_keys(Invoice::PAYMENT_METHODS))],
            'items'                 => 'required|array|min:1',
            'items.*.package_type_id' => 'required|integer',
            'items.*.size'          => 'nullable|string|max:20',
            'items.*.weight'        => 'nullable|numeric|min:0|max:999999.99',
            'items.*.length_cm'     => 'nullable|numeric|min:0|max:999999.99',
            'items.*.width_cm'      => 'nullable|numeric|min:0|max:999999.99',
            'items.*.height_cm'     => 'nullable|numeric|min:0|max:999999.99',
            'items.*.description'   => 'nullable|string|max:255',
            'items.*.price'         => 'required|numeric|min:0',
            'taxes'                 => 'present|array',
            'taxes.*.id'            => 'required|integer',
            'taxes.*.percent'       => 'required|numeric|min:0|max:100',
            'subtotal'              => 'required|numeric|min:0',
            'tax_total'             => 'required|numeric|min:0',
            'total'                 => 'required|numeric|min:0',
        ]);

        if ($validador->fails()) {
            return $this->resultado($uuid, 'failed', $validador->errors()->first());
        }

        $datos = $validador->validated();

        // Sedes y tipos de bulto de ESTA empresa: el ámbito global ya filtra,
        // así que un id ajeno simplemente no aparece.
        if (! Branch::whereKey($datos['pickup_branch_id'])->exists()
            || ! Branch::whereKey($datos['delivery_branch_id'])->exists()) {
            return $this->resultado($uuid, 'failed', 'La sede de origen o de destino ya no existe.');
        }

        $tipos = collect($datos['items'])->pluck('package_type_id')->unique();
        if (PackageType::whereIn('id', $tipos)->count() !== $tipos->count()) {
            return $this->resultado($uuid, 'failed', 'Uno de los tipos de bulto ya no existe.');
        }

        if ($motivo = $this->noCuadra($datos)) {
            return $this->resultado($uuid, 'failed', $motivo);
        }

        // Se cobró impuesto sin conexión y alguien lo borró después: la guía
        // quedaría con el impuesto en el total y sin el renglón que lo explica.
        // Es configuración, y por lo tanto corregible: se queda esperando.
        $idsImpuesto = collect($datos['taxes'])->pluck('id')->unique();
        if (Tax::whereIn('id', $idsImpuesto)->count() !== $idsImpuesto->count()) {
            return $this->resultado($uuid, 'error', 'Uno de los impuestos con que se cobró ya no existe. '
                . 'Restaurálo en Impuestos y la guía se sube sola.');
        }

        $vendedor = $this->vendedor($datos['sold_by'] ?? null, $sincroniza);

        if ($motivo = $this->bloqueoDeCredito($datos)) {
            return $this->resultado($uuid, 'error', $motivo);
        }

        // Cobrada en el mostrador sin conexión: la plata está en la gaveta y
        // tiene que entrar a un arqueo. Sin caja abierta de quien la cobró no
        // hay dónde; se espera a que la abra, igual que el formulario en línea
        // no guarda un contado sin caja.
        if ($datos['cobro'] === RegistroDeGuia::COBRO_PREPAID
            && $vendedor->puedeCobrar()
            && ! app(CajaService::class)->sesionPropiaAbierta($vendedor, $datos['pickup_branch_id'])) {
            return $this->resultado($uuid, 'error', "{$vendedor->name} no tiene una caja abierta en la sede: "
                . 'abrila para que este cobro entre al arqueo y la guía se sube sola.');
        }

        try {
            $invoice = app(RegistroDeGuia::class)->guardar(null, $this->columnas($datos, $uuid, $vendedor), $vendedor);
        } catch (UniqueConstraintViolationException) {
            // Otra pestaña la subió entre la consulta de arriba y esta.
            return $this->resultado($uuid, 'duplicate', guia: Invoice::where('client_uuid', $uuid)->first());
        } catch (\Throwable $e) {
            Log::error("Guía sin conexión {$uuid}: " . $e->getMessage());

            return $this->resultado($uuid, 'error', 'No se pudo guardar; se reintenta sola.');
        }

        return $this->resultado($uuid, 'synced', guia: $invoice->fresh());
    }

    /**
     * La guía tiene que cuadrar consigo misma.
     *
     * Los montos se respetan a propósito —son los que se cobraron y los que
     * dice el comprobante que se llevó el cliente, aunque la tarifa haya
     * cambiado desde entonces—, pero no se aceptan totales arbitrarios.
     */
    private function noCuadra(array $d): ?string
    {
        $cerca = fn (float $a, float $b) => abs($a - $b) < 0.02;

        $subtotal = collect($d['items'])->sum(fn ($i) => (float) $i['price']);
        if (! $cerca($subtotal, (float) $d['subtotal'])) {
            return 'El subtotal no coincide con la suma de los bultos.';
        }

        $base = round((float) $d['subtotal'] + (float) ($d['insurance_fee'] ?? 0)
            + (($d['home_delivery'] ?? false) ? (float) ($d['home_delivery_fee'] ?? 0) : 0)
            - (float) ($d['discount_amount'] ?? 0), 2);

        if ($base < 0) {
            return 'El descuento es mayor que el cobro.';
        }

        $porcentaje = collect($d['taxes'])->sum(fn ($t) => (float) $t['percent']);
        if (! $cerca(round($base * $porcentaje / 100, 2), (float) $d['tax_total'])) {
            return 'El impuesto no coincide con la base gravable.';
        }

        if (! $cerca(round($base + (float) $d['tax_total'], 2), (float) $d['total'])) {
            return 'El total no coincide con el desglose.';
        }

        return null;
    }

    /** Motivo por el que una guía a crédito todavía no puede entrar. */
    private function bloqueoDeCredito(array $d): ?string
    {
        if ($d['cobro'] !== RegistroDeGuia::COBRO_CREDIT) {
            return null;
        }

        $cliente = ! empty($d['sender_customer_id']) ? Customer::find($d['sender_customer_id']) : null;

        // Corregible: se puede reactivar al cliente o darle crédito.
        if (! $cliente) {
            return 'El cliente de crédito de esta guía ya no existe o no está activo.';
        }

        return app(CreditoService::class)->bloqueoPorLimite($cliente, (float) $d['total']);
    }

    /**
     * A quién se atribuye la guía: quien la recibió, no quien sincroniza.
     *
     * Solo se acepta un usuario de la misma empresa (el ámbito global ya lo
     * acota); si no, cae en quien sincroniza.
     */
    private function vendedor($id, User $sincroniza): User
    {
        if (! $id || (int) $id === $sincroniza->id) {
            return $sincroniza;
        }

        return User::where('is_active', true)->find($id) ?? $sincroniza;
    }

    /** Los datos del navegador, en las columnas que espera RegistroDeGuia. */
    private function columnas(array $d, string $uuid, User $vendedor): array
    {
        $cliente = $d['cobro'] === RegistroDeGuia::COBRO_CREDIT ? Customer::find($d['sender_customer_id']) : null;
        $domicilio = (bool) ($d['home_delivery'] ?? false);
        $ruta = ! empty($d['shipping_route_id']) ? ShippingRoute::find($d['shipping_route_id']) : null;

        return [
            'client_uuid'        => $uuid,
            'offline_reference'  => $d['offline_reference'] ?? null,
            // El navegador manda toISOString(), que siempre es UTC: se pasa a
            // la zona de la aplicación para que quede igual que una en línea.
            'created_at'         => Carbon::parse($d['created_at'])->setTimezone(config('app.timezone')),
            'pickup_branch_id'   => $d['pickup_branch_id'],
            'delivery_branch_id' => $d['delivery_branch_id'],
            // Solo si sigue uniendo las mismas sedes: es de donde sale la
            // fecha prometida.
            'shipping_route_id'  => $ruta
                && (int) $ruta->origin_branch_id === (int) $d['pickup_branch_id']
                && (int) $ruta->destination_branch_id === (int) $d['delivery_branch_id'] ? $ruta->id : null,
            'shipment_type'      => $d['shipment_type'] ?? null,
            'sender_name'        => $d['sender_name'],
            'sender_phone'       => $d['sender_phone'] ?? null,
            'sender_identification' => $d['sender_identification'] ?? null,
            'sender_identification_type' => $cliente?->identification_type
                ?? (filled($d['sender_identification'] ?? null) ? '01' : null),
            'sender_email'       => $cliente?->email,
            'sender_customer_id' => $cliente?->id,
            'recipient_name'     => $d['recipient_name'],
            'recipient_phone'    => $d['recipient_phone'] ?? null,
            'recipient_identification' => ($d['recipient_identification'] ?? null)
                ? preg_replace('/\D/', '', $d['recipient_identification']) : null,
            'recipient_identification_type' => filled($d['recipient_identification'] ?? null) ? '01' : null,
            'recipient_email'    => $d['recipient_email'] ?? null,
            // Sin conexión no se elige comprobante: sale tiquete.
            'bill_type'          => Invoice::BILL_TICKET,
            'bill_to'            => Invoice::BILL_TO_RECIPIENT,
            'declared_value'     => $d['declared_value'] ?? 0,
            'insurance_fee'      => $d['insurance_fee'] ?? 0,
            'home_delivery'      => $domicilio,
            'delivery_address'   => $domicilio ? ($d['delivery_address'] ?? null) : null,
            'home_delivery_fee'  => $domicilio ? ($d['home_delivery_fee'] ?? 0) : 0,
            'discount_amount'    => $d['discount_amount'] ?? 0,
            // La clave se comprobó en el navegador contra el verificador; lo
            // que se registra, como en línea, es quién aplicó el descuento.
            'discount_authorized_by' => (float) ($d['discount_amount'] ?? 0) > 0 ? $vendedor->id : null,
            'notes'              => $d['notes'] ?? null,
            'payment_method'     => $d['payment_method'],
            'assigned_to'        => null,
            'subtotal'           => $d['subtotal'],
            'tax_total'          => $d['tax_total'],
            'total'              => $d['total'],
            'cobro'              => $d['cobro'],
            'items'              => $d['items'],
            'tax_ids'            => collect($d['taxes'])->pluck('id')->all(),
        ];
    }

    private function resultado(string $uuid, string $estado, ?string $motivo = null, ?Invoice $guia = null): array
    {
        return array_filter([
            'client_uuid' => $uuid,
            'status'      => $estado,
            'reason'      => $motivo,
            'id'          => $guia?->id,
            'code'        => $guia?->code,
            'etiqueta'    => $guia ? route('invoices.etiqueta', $guia) : null,
            'ver'         => $guia ? route('invoices.show', $guia) : null,
            'awaiting_cashier' => $guia?->awaiting_cashier ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Ancho del rollo y tipo de impresora, para el comprobante provisional. */
    private function papel(User $usuario, ?CashRegister $caja): array
    {
        $caja ??= CashRegister::where('branch_id', $usuario->branch_id)->where('is_active', true)->first();

        $ancho = $caja?->receiptPaperWidthMm() ?? 80;
        $ancho = in_array($ancho, CashRegister::PAPER_WIDTHS, true) ? $ancho : 80;
        $matriz = (bool) $caja?->imprimeEnMatriz();

        return [
            'ancho'      => $ancho,
            'matriz'     => $matriz,
            'ancho_util' => $matriz ? CashRegister::ANCHO_IMPRIMIBLE_MATRIZ[$ancho] : $ancho,
        ];
    }
}
