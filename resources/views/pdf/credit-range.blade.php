<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
    .header { background-color: #1d4ed8; color: #fff; padding: 14px 18px; }
    .header h1 { margin: 0 0 3px 0; font-size: 18px; color: #fff; }
    .header .muted { color: #dbeafe; font-size: 10px; }
    .meta { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin-top: 14px; }
    .meta td { width: 33%; border: 1px solid #9ca3af; padding: 8px; vertical-align: top; }
    .meta .label { font-size: 9px; text-transform: uppercase; letter-spacing: .4px; color: #1d4ed8; display: block; margin-bottom: 3px; }
    h2 { font-size: 13px; margin: 18px 0 6px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background-color: #1f2937; color: #fff; padding: 6px 8px; text-align: left; font-size: 10px; text-transform: uppercase; }
    table.data td { padding: 5px 8px; border-bottom: 1px solid #d1d5db; }
    table.data tbody tr:nth-child(even) td { background-color: #f3f4f6; }
    .text-right, table.data th.text-right { text-align: right; }
    .resumen { width: 60%; margin-left: auto; margin-top: 12px; border-collapse: collapse; }
    .resumen td { padding: 4px 8px; text-align: right; }
    .resumen tr.total td { border-top: 2px solid #1f2937; font-weight: bold; font-size: 13px; padding-top: 6px; }
    .vacio { color: #6b7280; padding: 8px 0; }
    .legal { margin-top: 20px; font-size: 9px; color: #374151; border-top: 1px solid #9ca3af; padding-top: 7px; line-height: 1.5; }
</style>
</head>
<body>
    <div class="header">
        <h1>Estado de cuenta</h1>
        <div class="muted">{{ $company->commercial_name ?: $company->name }} · Servicio de encomiendas</div>
    </div>

    <table class="meta">
        <tr>
            <td><span class="label">Cliente</span>{{ $cliente->name }}<br>{{ $cliente->identification }}</td>
            <td><span class="label">Período</span>{{ $r['desde']->format('d/m/Y') }} – {{ $r['hasta']->format('d/m/Y') }}</td>
            <td><span class="label">Generado</span>{{ now()->format('d/m/Y H:i') }}<br>{{ auth()->user()?->name }}</td>
        </tr>
    </table>

    <h2>Encomiendas a crédito del período</h2>
    @if ($r['guias']->isEmpty())
        <p class="vacio">No hay encomiendas a crédito en estas fechas.</p>
    @else
        <table class="data">
            <thead>
                <tr><th>Guía</th><th>Fecha</th><th>Destinatario</th><th>Ruta</th><th>Estado de cuenta</th><th class="text-right">Total</th></tr>
            </thead>
            <tbody>
                @foreach ($r['guias'] as $g)
                    <tr>
                        <td>{{ $g->code }}</td>
                        <td>{{ $g->created_at?->format('d/m/Y') }}</td>
                        <td>{{ $g->recipient_name }}</td>
                        <td>{{ $g->pickupBranch?->prefixLabel() }} → {{ $g->deliveryBranch?->prefixLabel() }}</td>
                        <td>
                            @if ($g->creditStatement)
                                {{ $g->creditStatement->code }} · {{ $g->creditStatement->statusLabel() }}
                            @else
                                Sin cortar
                            @endif
                        </td>
                        <td class="text-right">₡{{ number_format((float) $g->total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Abonos del período</h2>
    @if ($r['abonos']->isEmpty())
        <p class="vacio">No hubo abonos en estas fechas.</p>
    @else
        <table class="data">
            <thead><tr><th>Fecha</th><th>Medio</th><th>Referencia</th><th>Aplicado a</th><th class="text-right">Monto</th></tr></thead>
            <tbody>
                @foreach ($r['abonos'] as $p)
                    <tr>
                        <td>{{ $p->paid_at?->format('d/m/Y') }}</td>
                        <td>{{ $p->paymentMethodLabel() }}</td>
                        <td>{{ $p->reference ?: '—' }}</td>
                        <td>{{ $p->statement?->code ?? 'Del más viejo al más nuevo' }}</td>
                        <td class="text-right">₡{{ number_format((float) $p->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="resumen">
        <tr><td>Encomiendas del período ({{ $r['guias']->count() }})</td><td>₡{{ number_format($r['consumido'], 2) }}</td></tr>
        <tr><td>Abonos del período</td><td>₡{{ number_format($r['abonado'], 2) }}</td></tr>
        <tr><td>Saldo cortado sin pagar, hoy</td><td>₡{{ number_format($r['facturado'], 2) }}</td></tr>
        <tr><td>Acumulado sin cortar, hoy</td><td>₡{{ number_format($r['sinCortar'], 2) }}</td></tr>
        <tr class="total"><td>Saldo total a la fecha</td><td>₡{{ number_format($r['saldoActual'], 2) }}</td></tr>
    </table>

    <div class="legal">
        Resumen informativo de las encomiendas a crédito y los abonos registrados entre las fechas indicadas.
        El saldo total corresponde a la fecha de emisión de este documento e incluye movimientos fuera del período.
        Para consultas sobre una guía en particular, cite su código.
    </div>
</body>
</html>
