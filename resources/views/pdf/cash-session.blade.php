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
    .dif-ok { color: #1b6e45; font-weight: bold; }
    .dif-mal { color: #a1352a; font-weight: bold; }
    .firmas { width: 100%; margin-top: 40px; border-collapse: separate; border-spacing: 18px 0; }
    .firmas td { width: 50%; border-top: 1px solid #111827; padding-top: 6px; text-align: center; font-size: 10px; color: #374151; }
</style>
</head>
<body>
    <div class="header">
        <h1>Cierre de caja · turno #{{ $sesion->id }}</h1>
        <div class="muted">{{ $company->commercial_name ?: $company->name }} · {{ $sesion->register?->name }} — {{ $sesion->branch?->name }}</div>
    </div>

    <table class="meta">
        <tr>
            <td><span class="label">Apertura</span>{{ $sesion->opened_at?->format('d/m/Y H:i') }}<br>{{ $sesion->opener?->name }}</td>
            <td><span class="label">Cierre</span>{{ $sesion->closed_at?->format('d/m/Y H:i') ?: 'Turno abierto' }}<br>{{ $sesion->closer?->name }}</td>
            <td><span class="label">Fondo inicial</span>₡{{ number_format((float) $sesion->opening_float, 2) }}</td>
            <td><span class="label">Generado</span>{{ now()->format('d/m/Y H:i') }}</td>
        </tr>
    </table>

    <h2>Cobros por medio de pago</h2>
    <table class="data">
        <thead><tr><th>Medio</th><th class="text-right">Cantidad</th><th class="text-right">Total</th></tr></thead>
        <tbody>
            @forelse ($porMedio as $medio)
                <tr>
                    <td>{{ $medio['etiqueta'] }}</td>
                    <td class="text-right">{{ $medio['cantidad'] }}</td>
                    <td class="text-right">₡{{ number_format($medio['total'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">Sin cobros en el turno.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Movimientos del turno</h2>
    <table class="data">
        <thead>
            <tr><th>Hora</th><th>Tipo</th><th>Referencia</th><th>Medio</th><th class="text-right">Monto</th></tr>
        </thead>
        <tbody>
            @forelse ($sesion->movements as $m)
                <tr>
                    <td>{{ $m->happened_at?->format('H:i') }}</td>
                    <td>{{ $m->typeLabel() }}</td>
                    <td>{{ $m->reference ?: $m->reason }}</td>
                    <td>{{ $m->paymentMethodLabel() }}</td>
                    <td class="text-right">{{ $m->type === 'out' ? '−' : '' }}₡{{ number_format((float) $m->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Sin movimientos.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($sesion->counts->isNotEmpty())
        <h2>Arqueo por denominación</h2>
        <table class="data">
            <thead><tr><th>Denominación</th><th class="text-right">Cantidad</th><th class="text-right">Subtotal</th></tr></thead>
            <tbody>
                @foreach ($sesion->counts->sortByDesc(fn ($c) => $c->denomination?->value) as $c)
                    @if ($c->quantity > 0)
                        <tr>
                            <td>{{ $c->denomination?->label() }}</td>
                            <td class="text-right">{{ $c->quantity }}</td>
                            <td class="text-right">₡{{ number_format((float) $c->subtotal, 2) }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="resumen">
        <tr><td>Efectivo esperado</td><td>₡{{ number_format((float) $sesion->expected_cash, 2) }}</td></tr>
        <tr><td>Efectivo contado</td><td>₡{{ number_format((float) $sesion->counted_cash, 2) }}</td></tr>
        <tr class="total">
            <td>{{ (float) $sesion->discrepancy < 0 ? 'Faltante' : ((float) $sesion->discrepancy > 0 ? 'Sobrante' : 'Diferencia') }}</td>
            <td class="{{ abs((float) $sesion->discrepancy) < 0.01 ? 'dif-ok' : 'dif-mal' }}">
                ₡{{ number_format(abs((float) $sesion->discrepancy), 2) }}
            </td>
        </tr>
    </table>

    @if ($sesion->closing_note)
        <div style="margin-top:14px;"><strong>Nota de cierre:</strong> {{ $sesion->closing_note }}</div>
    @endif

    <table class="firmas">
        <tr>
            <td>Firma del cajero<br>{{ $sesion->closer?->name ?: $sesion->opener?->name }}</td>
            <td>Recibido por supervisión<br>Nombre y fecha</td>
        </tr>
    </table>
</body>
</html>
