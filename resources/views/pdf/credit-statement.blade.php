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
    .meta td { width: 25%; border: 1px solid #9ca3af; padding: 8px; vertical-align: top; }
    .meta .label { font-size: 9px; text-transform: uppercase; letter-spacing: .4px; color: #1d4ed8; display: block; margin-bottom: 3px; }
    h2 { font-size: 13px; margin: 18px 0 6px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background-color: #1f2937; color: #fff; padding: 6px 8px; text-align: left; font-size: 10px; text-transform: uppercase; }
    table.data td { padding: 5px 8px; border-bottom: 1px solid #d1d5db; }
    table.data tbody tr:nth-child(even) td { background-color: #f3f4f6; }
    .text-right, table.data th.text-right { text-align: right; }
    .resumen { width: 55%; margin-left: auto; margin-top: 12px; border-collapse: collapse; }
    .resumen td { padding: 4px 8px; text-align: right; }
    .resumen tr.total td { border-top: 2px solid #1f2937; font-weight: bold; font-size: 13px; padding-top: 6px; }
    .vencido { color: #a1352a; font-weight: bold; }
    .legal { margin-top: 20px; font-size: 9px; color: #374151; border-top: 1px solid #9ca3af; padding-top: 7px; line-height: 1.5; }
</style>
</head>
<body>
    <div class="header">
        <h1>Estado de cuenta {{ $estado->code }}</h1>
        <div class="muted">{{ $company->commercial_name ?: $company->name }} · Servicio de encomiendas</div>
    </div>

    <table class="meta">
        <tr>
            <td><span class="label">Cliente</span>{{ $estado->customer?->name }}<br>{{ $estado->customer?->identification }}</td>
            <td><span class="label">Período</span>{{ $estado->periodoLabel() }}</td>
            <td><span class="label">Vence</span>{{ $estado->due_date?->format('d/m/Y') }}
                @if ($estado->estaVencido())<br><span class="vencido">{{ $estado->tramoAntiguedad() }}</span>@endif
            </td>
            <td><span class="label">Emitido</span>{{ $estado->issued_at?->format('d/m/Y H:i') }}<br>{{ $estado->issuer?->name }}</td>
        </tr>
    </table>

    <h2>Encomiendas del período</h2>
    <table class="data">
        <thead>
            <tr><th>Guía</th><th>Fecha</th><th>Destinatario</th><th>Ruta</th><th class="text-right">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($estado->guides as $g)
                <tr>
                    <td>{{ $g->code }}</td>
                    <td>{{ $g->created_at?->format('d/m/Y') }}</td>
                    <td>{{ $g->recipient_name }}</td>
                    <td>{{ $g->pickupBranch?->prefixLabel() }} → {{ $g->deliveryBranch?->prefixLabel() }}</td>
                    <td class="text-right">₡{{ number_format((float) $g->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($estado->payments->isNotEmpty())
        <h2>Abonos aplicados</h2>
        <table class="data">
            <thead><tr><th>Fecha</th><th>Medio</th><th>Referencia</th><th class="text-right">Monto</th></tr></thead>
            <tbody>
                @foreach ($estado->payments as $p)
                    <tr>
                        <td>{{ $p->paid_at?->format('d/m/Y') }}</td>
                        <td>{{ $p->paymentMethodLabel() }}</td>
                        <td>{{ $p->reference ?: '—' }}</td>
                        <td class="text-right">₡{{ number_format((float) $p->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="resumen">
        <tr><td>Total del período ({{ $estado->guides->count() }} guías)</td><td>₡{{ number_format((float) $estado->total, 2) }}</td></tr>
        <tr><td>Abonado</td><td>₡{{ number_format((float) $estado->paid, 2) }}</td></tr>
        <tr class="total"><td>Saldo pendiente</td><td>₡{{ number_format((float) $estado->balance, 2) }}</td></tr>
    </table>

    <div class="legal">
        Este documento detalla las encomiendas transportadas en el período indicado bajo la modalidad de crédito.
        El saldo pendiente vence el {{ $estado->due_date?->format('d/m/Y') }}. Para consultas sobre una guía en
        particular, cite su código.
    </div>
</body>
</html>
