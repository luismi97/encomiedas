<?php

namespace App\Livewire\Reportes;

use App\Models\Branch;
use App\Models\CashSession;
use App\Models\CreditStatement;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Services\CreditoService;
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
    public ?int $branchId = null;

    public const REPORTES = [
        'estados'    => 'Guías por estado',
        'desecho'    => 'Próximas a desecho y desechadas',
        'ventas'     => 'Ventas de contado',
        'cobrar'     => 'Cuentas por cobrar',
        'caja'       => 'Cierres de caja',
        'hacienda'   => 'Facturación electrónica',
        'rutas'      => 'Volumen por ruta',
        'entrega'    => 'Tiempo promedio de entrega',
    ];

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
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
        ])->layout('layouts.app', ['title' => 'Reportes']);
    }

    private function calcular(CreditoService $credito): array
    {
        return match ($this->reporte) {
            'estados'  => $this->porEstado(),
            'desecho'  => $this->desecho(),
            'ventas'   => $this->ventasContado(),
            'cobrar'   => $this->cuentasPorCobrar($credito),
            'caja'     => $this->cierresDeCaja(),
            'hacienda' => $this->facturacionElectronica(),
            'rutas'    => $this->volumenPorRuta(),
            'entrega'  => $this->tiempoDeEntrega(),
            default    => [],
        };
    }

    private function porEstado(): array
    {
        $filas = $this->guiasDelPeriodo()
            ->selectRaw('status, count(*) as cantidad, sum(total) as monto')
            ->groupBy('status')
            ->get()
            ->map(fn ($f) => [
                'etiqueta' => Invoice::STATUSES[$f->status] ?? $f->status,
                'cantidad' => (int) $f->cantidad,
                'monto'    => (float) $f->monto,
            ]);

        return ['columnas' => ['Estado', 'Guías', 'Monto'], 'filas' => $filas];
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

    private function ventasContado(): array
    {
        $filas = $this->guiasDelPeriodo()
            ->where('sale_condition', Invoice::SALE_CASH)
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

    private function cierresDeCaja(): array
    {
        $filas = CashSession::with(['register.branch', 'closer'])
            ->where('status', CashSession::STATUS_CLOSED)
            ->whereBetween('closed_at', [$this->desde(), $this->hasta()])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->get()
            ->map(fn (CashSession $s) => [
                'etiqueta' => ($s->register?->name ?? 'Caja') . ' · ' . $s->closed_at?->format('d/m/Y H:i'),
                'extra'    => $s->closer?->name . ' · esperado ₡' . number_format((float) $s->expected_cash, 2),
                'cantidad' => $s->cuadra() ? 0 : 1,
                'monto'    => (float) $s->discrepancy,
            ]);

        return ['columnas' => ['Turno', 'Cajero', 'Descuadres', 'Diferencia'], 'filas' => $filas, 'conExtra' => true];
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
