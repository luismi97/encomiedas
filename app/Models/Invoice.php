<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    use HasFactory;
    use BelongsToBranch;

    /**
     * Una guía es de dos sedes: la de origen y la de destino. El cajero de
     * cualquiera de las dos tiene que verla, porque una la recibe y la otra la
     * entrega.
     */
    public function branchColumns(): array
    {
        return ['pickup_branch_id', 'delivery_branch_id'];
    }

    public const BILL_TICKET  = 'ticket';
    public const BILL_INVOICE = 'invoice';

    /** Etiquetas de cara al usuario, no los códigos de Hacienda. */
    public const BILL_TYPES = [
        self::BILL_TICKET  => 'Tiquete electrónico',
        self::BILL_INVOICE => 'Factura electrónica',
    ];

    /*
     | Ciclo de vida de la guía.
     |
     | Los cinco valores originales se conservan tal cual (pending, in_transit,
     | delivered, returned, cancelled) aunque su etiqueta haya cambiado: sobre
     | 'delivered' cuelga el disparador de la facturación electrónica, y
     | renombrar el valor habría roto ese flujo sin que nada avisara.
     */
    public const STATUS_PENDING        = 'pending';         // Recibido en sede origen
    public const STATUS_READY          = 'ready';           // Listo para envío
    public const STATUS_DISPATCHED     = 'dispatched';      // Salió en un cierre de envío
    public const STATUS_IN_TRANSIT     = 'in_transit';      // En camino
    public const STATUS_AT_DESTINATION = 'at_destination';  // Llegó a la sede destino
    public const STATUS_DELIVERED      = 'delivered';       // Entregado al destinatario
    public const STATUS_NEAR_DISPOSAL  = 'near_disposal';   // Próximo a desecho
    public const STATUS_DISPOSED       = 'disposed';        // Desechado
    public const STATUS_RETURNED       = 'returned';        // Devuelto al remitente
    public const STATUS_CANCELLED      = 'cancelled';       // Anulado

    public const STATUSES = [
        self::STATUS_PENDING        => 'Recibido',
        self::STATUS_READY          => 'Listo para envío',
        self::STATUS_DISPATCHED     => 'Enviado',
        self::STATUS_IN_TRANSIT     => 'En camino',
        self::STATUS_AT_DESTINATION => 'Llegó al destino',
        self::STATUS_DELIVERED      => 'Entregado',
        self::STATUS_NEAR_DISPOSAL  => 'Próximo a desecho',
        self::STATUS_DISPOSED       => 'Desechado',
        self::STATUS_RETURNED       => 'Devuelto',
        self::STATUS_CANCELLED      => 'Anulado',
    ];

    /** Estados finales: de aquí no se sale por el flujo normal. */
    public const FINAL_STATUSES = [
        self::STATUS_DELIVERED,
        self::STATUS_DISPOSED,
        self::STATUS_RETURNED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Transiciones permitidas. Fuera de esta tabla no hay cambio de estado:
     * evita que un escaneo mal hecho mande una guía entregada de vuelta a
     * "recibido" y desordene la bitácora.
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING        => [self::STATUS_READY, self::STATUS_CANCELLED],
        self::STATUS_READY          => [self::STATUS_DISPATCHED, self::STATUS_PENDING, self::STATUS_CANCELLED],
        self::STATUS_DISPATCHED     => [self::STATUS_IN_TRANSIT, self::STATUS_AT_DESTINATION, self::STATUS_RETURNED],
        self::STATUS_IN_TRANSIT     => [self::STATUS_AT_DESTINATION, self::STATUS_RETURNED],
        self::STATUS_AT_DESTINATION => [self::STATUS_DELIVERED, self::STATUS_NEAR_DISPOSAL, self::STATUS_RETURNED],
        self::STATUS_NEAR_DISPOSAL  => [self::STATUS_DELIVERED, self::STATUS_DISPOSED, self::STATUS_RETURNED],
        self::STATUS_DELIVERED      => [],
        self::STATUS_DISPOSED       => [],
        self::STATUS_RETURNED       => [],
        self::STATUS_CANCELLED      => [],
    ];

    /*
     | Condición de venta del comprobante (catálogo de Hacienda).
     |
     | Estaba fija en configuración; con crédito deja de ser global, porque la
     | misma empresa cobra de contado en mostrador y a crédito a sus clientes
     | con convenio.
     */
    public const SALE_CASH   = '01';
    public const SALE_CREDIT = '02';

    /** Medios de pago; las llaves se traducen al catálogo de Hacienda en Catalogs::paymentMethod(). */
    public const PAYMENT_METHODS = [
        'cash'     => 'Efectivo',
        'card'     => 'Tarjeta',
        'sinpe'    => 'SINPE Móvil',
        'transfer' => 'Transferencia',
        'other'    => 'Otro',
    ];

    public const STATUS_COLORS = [
        self::STATUS_PENDING        => 'yellow',
        self::STATUS_READY          => 'yellow',
        self::STATUS_DISPATCHED     => 'blue',
        self::STATUS_IN_TRANSIT     => 'blue',
        self::STATUS_AT_DESTINATION => 'blue',
        self::STATUS_DELIVERED      => 'green',
        self::STATUS_NEAR_DISPOSAL  => 'amber',
        self::STATUS_DISPOSED       => 'gray',
        self::STATUS_RETURNED       => 'red',
        self::STATUS_CANCELLED      => 'gray',
    ];

    /** Clases Tailwind completas (evita purgar clases generadas dinámicamente). */
    public const STATUS_BADGE_CLASSES = [
        self::STATUS_PENDING        => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200',
        self::STATUS_READY          => 'bg-yellow-100 text-yellow-900 dark:bg-yellow-900/50 dark:text-yellow-100',
        self::STATUS_DISPATCHED     => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
        self::STATUS_IN_TRANSIT     => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
        self::STATUS_AT_DESTINATION => 'bg-cyan-100 text-cyan-900 dark:bg-cyan-900/40 dark:text-cyan-200',
        self::STATUS_DELIVERED      => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
        self::STATUS_NEAR_DISPOSAL  => 'bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-100',
        self::STATUS_DISPOSED       => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        self::STATUS_RETURNED       => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
        self::STATUS_CANCELLED      => 'bg-gray-100 text-gray-800 dark:bg-gray-700/60 dark:text-gray-300',
    ];

    protected $fillable = [
        'code',
        'bill_type',
        'sender_customer_id',
        'recipient_customer_id',
        'shipment_type',
        'declared_value',
        'arrived_at',
        'disposal_warned_at',
        'disposed_at',
        'received_by_name',
        'received_by_identification',
        'delivery_signature',
        'delivery_photo_path',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'status',
        'pickup_branch_id',
        'delivery_branch_id',
        'sender_name',
        'sender_phone',
        'sender_identification',
        'recipient_name',
        'recipient_phone',
        'recipient_identification_type',
        'recipient_identification',
        'recipient_email',
        'subtotal',
        'discount_amount',
        'tax_total',
        'total',
        'payment_method',
        'payment_timing',
        'collected_at',
        'sale_condition',
        'credit_term_days',
        'credit_statement_id',
        'notes',
        'created_by',
        'assigned_to',
        'delivered_at',
        'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:5',
            'discount_amount' => 'decimal:5',
            'tax_total' => 'decimal:5',
            'total' => 'decimal:5',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'arrived_at' => 'datetime',
            'disposal_warned_at' => 'datetime',
            'disposed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'collected_at' => 'datetime',
            'declared_value' => 'decimal:2',
        ];
    }

    public function senderCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'sender_customer_id');
    }

    public function recipientCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'recipient_customer_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(GuideIncident::class)->latest('reported_at');
    }

    public function tieneIncidenciasAbiertas(): bool
    {
        return $this->incidents()->whereNull('resolved_at')->exists();
    }

    public function printLogs(): HasMany
    {
        return $this->hasMany(PrintLog::class)->latest('id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** ¿Se registró quién retiró el paquete? */
    public function tieneEvidenciaDeEntrega(): bool
    {
        return filled($this->received_by_name) || filled($this->delivery_signature);
    }

    /**
     * Una guía ya despachada no se anula: viaja en un camión y hay un
     * manifiesto firmado que la incluye. Se devuelve, que es otra cosa.
     */
    public function sePuedeAnular(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_READY], true);
    }

    public function creditStatement(): BelongsTo
    {
        return $this->belongsTo(CreditStatement::class, 'credit_statement_id');
    }

    public function esCredito(): bool
    {
        return $this->sale_condition === self::SALE_CREDIT;
    }

    /*
     | Quién paga el flete y cuándo.
     |
     | Va aparte de la condición de venta: «por cobrar» sigue siendo contado
     | —se paga en el momento del retiro—, pero el dinero no entra en la caja
     | de origen sino en la de destino, y hasta entonces no es un ingreso.
     */
    public const TIMING_PREPAID = 'prepaid';
    public const TIMING_COLLECT = 'collect';

    public const PAYMENT_TIMINGS = [
        self::TIMING_PREPAID => 'Pagado',
        self::TIMING_COLLECT => 'Por cobrar',
    ];

    public function esPorCobrar(): bool
    {
        return $this->payment_timing === self::TIMING_COLLECT && ! $this->esCredito();
    }

    /** Un «por cobrar» que todavía no se cobró: es lo que debe pagar quien retira. */
    public function tieneCobroPendiente(): bool
    {
        return $this->esPorCobrar() && $this->collected_at === null;
    }

    /**
     * Guías cuyo dinero de verdad se recibió.
     *
     * Un flete por cobrar es contado, pero mientras nadie lo pague no es un
     * ingreso: sumarlo declara como cobrado lo que no está en ninguna gaveta.
     * Vive como scope y no suelto en cada reporte para que el próximo que se
     * escriba no vuelva a contarlo.
     */
    public function scopeCobradas(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('payment_timing', '!=', self::TIMING_COLLECT)
            ->orWhereNotNull('collected_at'));
    }

    /** Lo contrario: plata prometida que todavía no entró. */
    public function scopePorCobrarPendientes(Builder $query): Builder
    {
        return $query->where('payment_timing', self::TIMING_COLLECT)
            ->whereNull('collected_at')
            ->where('sale_condition', self::SALE_CASH);
    }

    public function timingLabel(): string
    {
        if ($this->esCredito()) {
            return 'A crédito';
        }

        return self::PAYMENT_TIMINGS[$this->payment_timing] ?? 'Pagado';
    }

    /** Ya facturada en un corte: no puede volver a entrar en otro. */
    public function fueCortada(): bool
    {
        return $this->credit_statement_id !== null;
    }

    public function saleConditionLabel(): string
    {
        return \App\Services\Hacienda\Catalogs::saleConditionLabel($this->sale_condition);
    }

    /** Líneas de manifiesto donde aparece esta guía. */
    public function dispatchLines(): HasMany
    {
        return $this->hasMany(DispatchGuide::class);
    }

    /** Bitácora de estados, de la más vieja a la más nueva. */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(GuideStatusHistory::class)->orderBy('happened_at');
    }

    /** ¿Se puede pasar a este estado desde el actual? */
    public function puedePasarA(string $estado): bool
    {
        return in_array($estado, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Estados a los que se puede mover ahora, con su etiqueta. */
    public function siguientesEstados(): array
    {
        return collect(self::TRANSITIONS[$this->status] ?? [])
            ->mapWithKeys(fn (string $e) => [$e => self::STATUSES[$e]])
            ->all();
    }

    public function estaCerrada(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    /** URL que lleva el QR: abre el seguimiento público de esta guía. */
    public function trackingUrl(): string
    {
        return url('/rastreo/' . $this->code);
    }

    public function shipmentTypeLabel(): string
    {
        return Rate::SHIPMENT_TYPES[$this->shipment_type] ?? '—';
    }

    public function pickupBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'pickup_branch_id');
    }

    public function deliveryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'delivery_branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->latest();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceTax::class);
    }

    /** Comprobante electrónico vigente (el de mayor id). */
    /**
     * El comprobante principal (factura o tiquete) de la encomienda.
     *
     * Se restringe a los tipos 01/04 a propósito: las notas de crédito y
     * débito también cuelgan de esta factura y son más recientes, así que sin
     * el filtro latestOfMany() devolvería la nota en lugar del comprobante que
     * la nota corrige.
     */
    public function electronicInvoice(): HasOne
    {
        // La restricción va DENTRO de ofMany(): puesta por fuera, la subconsulta
        // seguiría eligiendo el comprobante más reciente —la nota— y el filtro
        // externo lo descartaría después, devolviendo null.
        return $this->hasOne(ElectronicInvoice::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->whereIn('document_type', ['01', '04'])
        );
    }

    /** Notas de crédito/débito emitidas contra el comprobante de esta encomienda. */
    public function electronicNotes(): HasMany
    {
        return $this->hasMany(ElectronicInvoice::class)
            ->whereIn('document_type', ['02', '03'])
            ->latest();
    }

    public function electronicInvoices(): HasMany
    {
        return $this->hasMany(ElectronicInvoice::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'gray';
    }

    public function statusBadgeClasses(): string
    {
        return self::STATUS_BADGE_CLASSES[$this->status] ?? self::STATUS_BADGE_CLASSES[self::STATUS_CANCELLED];
    }

    /** ¿Tiene datos suficientes del receptor para ser Factura (con cédula) en vez de Tiquete? */
    /**
     * ¿Se emite Factura Electrónica (con receptor identificado) o Tiquete?
     *
     * Manda la elección explícita del formulario. La identificación se sigue
     * exigiendo porque una FE sin receptor identificado la rechaza Hacienda.
     */
    public function receptorIdentificado(): bool
    {
        return $this->bill_type === self::BILL_INVOICE && filled($this->recipient_identification);
    }

    public function wantsInvoice(): bool
    {
        return $this->bill_type === self::BILL_INVOICE;
    }

    public function billTypeLabel(): string
    {
        return self::BILL_TYPES[$this->bill_type] ?? self::BILL_TYPES[self::BILL_TICKET];
    }
}
