<?php

namespace App\Livewire\Reportes;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CreditStatement;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\CompanySetting;
use App\Models\User;
use App\Notifications\EnviarReporteContable;
use App\Services\CajaService;
use App\Services\CreditoService;
use App\Services\ReporteContable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Los ocho reportes del requisito, en una sola pantalla con filtro de período.
 *
 * Van juntos y no en ocho pantallas porque se consultan de a varios a la vez —
 * quien revisa el mes mira ventas, caja y cobranza en la misma sentada— y todos
 * comparten el mismo filtro de fechas y sede.
 */
class ReportePanel extends Component
{
    public string $reporte = 'estados';
    public string $from = '';
    public string $to = '';
    public $branchId = null;

    /** Cédula del cliente cuyo detalle se mira en «Facturas por cliente». */
    public string $cliente = '';

    /** Cajero de «Cierres de caja»: quien abrió el turno y responde por el arqueo. */
    public $cajeroId = null;

    /** Turno cuyo detalle se mira en «Cierres de caja». */
    public ?int $turno = null;

    public const REPORTES = [
        'estados'    => 'Guías por estado',
        'desecho'    => 'Próximas a desecho y desechadas',
        'ventas'     => 'Ventas de contado',
        'cobro'      => 'Cobrado y por cobrar',
        'cobrar'     => 'Cuentas por cobrar',
        'caja'       => 'Cierres de caja',
        'hacienda'   => 'Facturación electrónica',
        'clientes'   => 'Facturas por cliente',
        'rutas'      => 'Volumen por ruta',
        'entrega'    => 'Tiempo promedio de entrega',
        'contable'   => 'Reporte contable (ventas e IVA)',
    ];

    /** Solo administración: es lo fiscal, lo que se declara. */
    public const SOLO_ADMIN = ['contable'];

    /** A quién se manda el reporte contable. */
    public string $correoContador = '';

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->correoContador = (string) CompanySetting::instance()->accountant_email;

        // Desde Clientes se llega con ?reporte=clientes&cliente=<cédula>.
        if (array_key_exists($reporte = (string) request()->query('reporte'), self::REPORTES)) {
            $this->reporte = $reporte;
        }
        $this->cliente = preg_replace('/\D/', '', (string) request()->query('cliente'));

        // Cada fila de «Cierres de caja» es un link a ?reporte=caja&turno=<id>,
        // para poder abrir el detalle en otra pestaña.
        $this->turno = ((int) request()->query('turno')) ?: null;

        // Lo que un cliente tiene facturado no es «lo de este mes»: se abre
        // con el año, que es lo que suele preguntar.
        if ($this->cliente !== '') {
            $this->from = now()->startOfYear()->toDateString();
        }
    }

    public function verCliente(string $cedula): void
    {
        $this->cliente = preg_replace('/\D/', '', $cedula);
    }

    public function verTurno(int $id): void
    {
        $this->turno = $id;
    }

    public function updatedReporte(): void
    {
        $this->cliente = '';
        $this->turno = null;
    }

    /** Los reportes que este usuario puede elegir. */
    public function reportesDisponibles(): array
    {
        return auth()->user()->isAdmin()
            ? self::REPORTES
            : array_diff_key(self::REPORTES, array_flip(self::SOLO_ADMIN));
    }

    public function enviarAlContador(ReporteContable $reporte): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $this->validate([
            'correoContador' => 'required|email|max:150',
            'from'           => 'required|date',
            'to'             => 'required|date|after_or_equal:from',
        ], [
            'correoContador.required' => 'Digitá el correo del contador.',
            'correoContador.email'    => 'El correo no es válido.',
            'to.after_or_equal'       => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        $datos = $reporte->generar($this->from, $this->to, $this->branchId ? (int) $this->branchId : null);

        Notification::route('mail', $this->correoContador)->notify(new EnviarReporteContable($datos));

        CompanySetting::instance()->forceFill(['accountant_email' => $this->correoContador])->save();

        session()->flash('success', "Reporte contable enviado a {$this->correoContador}.");
    }

    private function desde(): Carbon
    {
        return Carbon::parse($this->from)->startOfDay();
    }

    private function hasta(): Carbon
    {
        return Carbon::parse($this->to)->endOfDay();
    }

    /** Guías del período, ya acotadas por sede si se eligió una. */
    private function guiasDelPeriodo()
    {
        return Invoice::query()
            ->whereBetween('created_at', [$this->desde(), $this->hasta()])
            ->when($this->branchId, fn ($q) => $q->where(fn ($w) => $w
                ->where('pickup_branch_id', $this->branchId)
                ->orWhere('delivery_branch_id', $this->branchId)));
    }

    public function render(CreditoService $credito)
    {
        return view('livewire.reportes.reporte-panel', [
            'datos'    => $this->calcular($credito),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            // Solo quienes han abierto algún turno: un chofer no tiene nada que filtrar.
            'cajeros'  => $this->reporte === 'caja'
                ? User::whereIn('id', CashSession::select('opened_by'))->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->layout('layouts.app', ['title' => 'Reportes']);
    }

    private function calcular(CreditoService $credito): array
    {
        // Un cajero que llegara con ?reporte=contable no lo ve.
        if (! array_key_exists($this->reporte, $this->reportesDisponibles())) {
            $this->reporte = 'estados';
        }

        return match ($this->reporte) {
            'contable' => app(ReporteContable::class)->generar($this->from, $this->to, $this->branchId ? (int) $this->branchId : null),
            'estados'  => $this->porEstado(),
            'desecho'  => $this->desecho(),
            'ventas'   => $this->ventasContado(),
            'cobro'    => $this->porCobro(),
            'cobrar'   => $this->cuentasPorCobrar($credito),
            'caja'     => $this->cierresDeCaja(),
            'hacienda' => $this->facturacionElectronica(),
            'clientes' => $this->facturasPorCliente(),
            'rutas'    => $this->volumenPorRuta(),
            'entrega'  => $this->tiempoDeEntrega(),
            default    => [],
        };
    }

    /**
     * Guías por estado, separando cuánto de ese monto todavía está por cobrar.
     *
     * Sin la separación, un estado con ₡200.000 se leía como ₡200.000 cobrados
     * aunque la mitad fueran fletes que paga quien retira y nadie ha retirado.
     */
    private function porEstado(): array
    {
        // Un solo recorrido por la base: agrupa por estado y, dentro de cada
        // uno, aparta lo que sigue sin cobrarse.
        $pendientePorEstado = $this->guiasDelPeriodo()
            ->porCobrarPendientes()
            ->selectRaw('status, sum(total) as pendiente')
            ->groupBy('status')
            ->pluck('pendiente', 'status');

        $filas = $this->guiasDelPeriodo()
            ->selectRaw('status, count(*) as cantidad, sum(total) as monto')
            ->groupBy('status')
            ->get()
            ->map(fn ($f) => [
                'etiqueta'   => Invoice::STATUSES[$f->status] ?? $f->status,
                'cantidad'   => (int) $f->cantidad,
                'monto'      => (float) $f->monto,
                'por_cobrar' => round((float) ($pendientePorEstado[$f->status] ?? 0), 2),
            ]);

        return [
            'columnas' => ['Estado', 'Guías', 'Monto', 'Por cobrar'],
            'filas' => $filas,
            'conPorCobrar' => true,
        ];
    }

    private function desecho(): array
    {
        $filas = $this->guiasDelPeriodo()
            ->whereIn('status', [Invoice::STATUS_NEAR_DISPOSAL, Invoice::STATUS_DISPOSED])
            ->with(['pickupBranch', 'deliveryBranch'])
            ->get()
            ->map(fn (Invoice $g) => [
                'etiqueta' => $g->code,
                'extra'    => $g->statusLabel() . ' · ' . ($g->deliveryBranch?->name ?? ''),
                'cantidad' => $g->arrived_at ? (int) $g->arrived_at->diffInDays(now()) : 0,
                'monto'    => (float) $g->total,
            ]);

        return ['columnas' => ['Guía', 'Situación', 'Días en destino', 'Monto'], 'filas' => $filas, 'conExtra' => true];
    }

    /**
     * Ventas de contado: solo lo que de verdad se cobró.
     *
     * Un flete por cobrar también es contado, así que entraba acá completo
     * aunque nadie lo hubiera pagado todavía: el reporte declaraba como ingreso
     * dinero que no estaba en ninguna gaveta. Ahora entra cuando se cobra, que
     * es cuando queda sellado collected_at.
     */
    private function ventasContado(): array
    {
        $filas = $this->guiasDelPeriodo()
            ->where('sale_condition', Invoice::SALE_CASH)
            ->cobradas()
            ->selectRaw('payment_method, count(*) as cantidad, sum(total) as monto')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($f) => [
                'etiqueta' => Invoice::PAYMENT_METHODS[$f->payment_method] ?? $f->payment_method,
                'cantidad' => (int) $f->cantidad,
                'monto'    => (float) $f->monto,
            ]);

        return ['columnas' => ['Medio de pago', 'Guías', 'Monto'], 'filas' => $filas];
    }

    /**
     * Cómo se cobra cada guía: pagado, por cobrar pendiente, por cobrar ya
     * cobrado y a crédito.
     *
     * Separa el dinero recibido del prometido, que es lo que «Guías por estado»
     * no distinguía: mostraba el monto de todas juntas.
     */
    private function porCobro(): array
    {
        $base = fn () => $this->guiasDelPeriodo();

        $resumen = fn ($consulta) => [
            'cantidad' => (clone $consulta)->count(),
            'monto'    => round((float) (clone $consulta)->sum('total'), 2),
        ];

        $pagadas    = $resumen($base()->where('sale_condition', Invoice::SALE_CASH)->where('payment_timing', Invoice::TIMING_PREPAID)->where('awaiting_cashier', false));
        $enCaja     = $resumen($base()->where('awaiting_cashier', true));
        $cobradas   = $resumen($base()->where('payment_timing', Invoice::TIMING_COLLECT)->whereNotNull('collected_at'));
        $pendientes = $resumen($base()->porCobrarPendientes());
        $credito    = $resumen($base()->where('sale_condition', Invoice::SALE_CREDIT));

        $filas = collect([
            ['etiqueta' => 'Pagadas en origen',        'extra' => 'Dinero recibido',  ...$pagadas],
            ['etiqueta' => 'Contado · sin cobrar en caja', 'extra' => 'NO es dinero aún', ...$enCaja],
            ['etiqueta' => 'Por cobrar · ya cobradas', 'extra' => 'Dinero recibido',  ...$cobradas],
            ['etiqueta' => 'Por cobrar · pendientes',  'extra' => 'NO es dinero aún', ...$pendientes],
            ['etiqueta' => 'A crédito',                'extra' => 'NO es dinero aún', ...$credito],
        ])->filter(fn ($f) => $f['cantidad'] > 0)->values();

        return ['columnas' => ['Cobro', 'Situación', 'Guías', 'Monto'], 'filas' => $filas, 'conExtra' => true];
    }

    private function cuentasPorCobrar(CreditoService $credito): array
    {
        $filas = collect($credito->antiguedadDeSaldos())
            ->map(fn ($datos, $tramo) => [
                'etiqueta' => $tramo,
                'cantidad' => $datos['cantidad'],
                'monto'    => $datos['total'],
            ])
            ->values();

        return ['columnas' => ['Antigüedad', 'Estados', 'Saldo'], 'filas' => $filas];
    }

    /**
     * Turnos cerrados en el período, y el detalle de uno al elegirlo.
     *
     * El cajero es quien abrió el turno, no quien lo cerró: el arqueo responde
     * por quien manejó el dinero, y un administrador que cierra el turno de
     * alguien que se fue sin hacerlo no pasa a ser el dueño del faltante.
     */
    private function cierresDeCaja(): array
    {
        if ($this->turno && $detalle = $this->detalleDeTurno($this->turno)) {
            return $detalle;
        }

        $this->turno = null;

        $filas = CashSession::with(['branch', 'register.branch', 'opener', 'closer'])
            ->where('status', CashSession::STATUS_CLOSED)
            ->whereBetween('closed_at', [$this->desde(), $this->hasta()])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->when($this->cajeroId, fn ($q) => $q->where('opened_by', $this->cajeroId))
            ->latest('closed_at')
            ->get();

        return ['vista' => 'resumen', 'filas' => $filas];
    }

    private function detalleDeTurno(int $id): ?array
    {
        $sesion = CashSession::with([
            'branch', 'register.branch', 'opener', 'closer',
            'movements.invoice:id,code', 'movements.creator:id,name', 'counts.denomination',
        ])->find($id);

        if (! $sesion) {
            return null;
        }

        $efectivo = $sesion->movements->where('payment_method', 'cash');

        return [
            'vista'    => 'detalle',
            'sesion'   => $sesion,
            'porMedio' => app(CajaService::class)->totalesPorMedio($sesion),
            // De dónde sale el esperado, para poder rehacer la cuenta a mano.
            'desglose' => [
                'cobros'   => (float) $efectivo->where('type', CashMovement::TYPE_SALE)->sum('amount'),
                'entradas' => (float) $efectivo->where('type', CashMovement::TYPE_IN)->sum('amount'),
                'salidas'  => (float) $efectivo->where('type', CashMovement::TYPE_OUT)->sum('amount'),
            ],
        ];
    }

    private function facturacionElectronica(): array
    {
        $filas = ElectronicInvoice::query()
            ->whereBetween('created_at', [$this->desde(), $this->hasta()])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->selectRaw('status, count(*) as cantidad, sum(total) as monto')
            ->groupBy('status')
            ->get()
            ->map(fn ($f) => [
                'etiqueta' => ElectronicInvoice::STATUSES[$f->status] ?? $f->status,
                'cantidad' => (int) $f->cantidad,
                'monto'    => (float) $f->monto,
            ]);

        return ['columnas' => ['Estado en Hacienda', 'Comprobantes', 'Monto'], 'filas' => $filas];
    }

    /**
     * Los comprobantes emitidos a nombre de cada cliente.
     *
     * Por la cédula del receptor y no por el cliente registrado: a quién se
     * factura puede ser el remitente, el destinatario u otra persona, y lo que
     * vale ante Hacienda es la cédula que quedó en el comprobante. Los
     * tiquetes no tienen receptor, así que no entran.
     *
     * El monto es lo aceptado, con las notas de crédito restando: es lo que
     * de verdad quedó facturado al cliente.
     */
    private function facturasPorCliente(): array
    {
        $comprobantes = ElectronicInvoice::query()
            ->with('invoice:id,code')
            ->whereBetween('created_at', [$this->desde(), $this->hasta()])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->whereIn('document_type', ['01', '02', '03'])
            ->latest('id')
            ->get()
            ->filter(fn (ElectronicInvoice $c) => filled($c->receptor_data['numero'] ?? null));

        $neto = fn (ElectronicInvoice $c) => $c->status === ElectronicInvoice::STATUS_ACCEPTED
            ? ($c->document_type === '03' ? -1 : 1) * (float) $c->total
            : 0.0;

        if ($this->cliente !== '') {
            $suyos = $comprobantes->filter(fn ($c) => preg_replace('/\D/', '', $c->receptor_data['numero']) === $this->cliente);

            return [
                'vista'  => 'detalle',
                'nombre' => $suyos->first()?->receptor_data['nombre']
                    ?? \App\Models\Customer::where('identification', $this->cliente)->value('name')
                    ?? $this->cliente,
                'filas'  => $suyos->map(fn (ElectronicInvoice $c) => [
                    'id'          => $c->id,
                    'fecha'       => ($c->issued_at ?? $c->created_at)?->format('d/m/Y'),
                    'tipo'        => $c->typeLabel(),
                    'consecutivo' => $c->consecutivo,
                    'guia'        => $c->invoice,
                    'estado'      => $c->status,
                    'estadoNombre' => $c->statusLabel(),
                    'total'       => (float) $c->total,
                    'exonerado'   => ! empty($c->receptor_data['exoneracion']),
                ])->values(),
                'monto'  => round($suyos->sum($neto), 2),
            ];
        }

        $problemas = [ElectronicInvoice::STATUS_REJECTED, ElectronicInvoice::STATUS_ERROR];

        return [
            'vista' => 'resumen',
            'filas' => $comprobantes
                ->groupBy(fn ($c) => preg_replace('/\D/', '', $c->receptor_data['numero']))
                ->map(fn ($grupo, $cedula) => [
                    'cedula'     => (string) $cedula,
                    // El más reciente: si cambió de razón social, la de hoy.
                    'nombre'     => $grupo->first()->receptor_data['nombre'] ?? (string) $cedula,
                    'cantidad'   => $grupo->count(),
                    'aceptados'  => $grupo->where('status', ElectronicInvoice::STATUS_ACCEPTED)->count(),
                    'problemas'  => $grupo->whereIn('status', $problemas)->count(),
                    'pendientes' => $grupo->whereNotIn('status', [ElectronicInvoice::STATUS_ACCEPTED, ...$problemas])->count(),
                    'monto'      => round($grupo->sum($neto), 2),
                ])
                ->sortByDesc('monto')
                ->values(),
        ];
    }

    private function volumenPorRuta(): array
    {
        $filas = $this->guiasDelPeriodo()
            ->with(['pickupBranch:id,prefix,name', 'deliveryBranch:id,prefix,name'])
            ->get()
            ->groupBy(fn (Invoice $g) => ($g->pickupBranch?->prefixLabel() ?? '?') . ' → ' . ($g->deliveryBranch?->prefixLabel() ?? '?'))
            ->map(fn ($grupo, $ruta) => [
                'etiqueta' => $ruta,
                'cantidad' => $grupo->count(),
                'monto'    => round((float) $grupo->sum('total'), 2),
            ])
            ->sortByDesc('cantidad')
            ->values();

        return ['columnas' => ['Ruta', 'Guías', 'Monto'], 'filas' => $filas];
    }

    /**
     * Tiempo de recepción a entrega, por ruta. Es el indicador de servicio: no
     * cuántas se movieron, sino cuánto tardaron.
     */
    private function tiempoDeEntrega(): array
    {
        $filas = $this->guiasDelPeriodo()
            ->where('status', Invoice::STATUS_DELIVERED)
            ->whereNotNull('delivered_at')
            ->with(['pickupBranch:id,prefix', 'deliveryBranch:id,prefix'])
            ->get()
            ->groupBy(fn (Invoice $g) => ($g->pickupBranch?->prefixLabel() ?? '?') . ' → ' . ($g->deliveryBranch?->prefixLabel() ?? '?'))
            ->map(function ($grupo, $ruta) {
                $horas = $grupo->map(fn (Invoice $g) => $g->created_at->diffInHours($g->delivered_at));

                return [
                    'etiqueta' => $ruta,
                    'cantidad' => $grupo->count(),
                    'monto'    => round($horas->avg() / 24, 1), // días promedio
                ];
            })
            ->sortByDesc('cantidad')
            ->values();

        return ['columnas' => ['Ruta', 'Entregas', 'Días promedio'], 'filas' => $filas, 'sinMoneda' => true];
    }
}
