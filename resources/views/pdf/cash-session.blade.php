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
    @php
        $variosDias = $sesion->opened_at && ! $sesion->opened_at->isSameDay($sesion->closed_at ?? now());
        $efectivo = $sesion->movements->where('payment_method', 'cash');
        $desglose = [
            'cobros'   => (float) $efectivo->where('type', 'sale')->sum('amount'),
            'entradas' => (float) $efectivo->where('type', 'in')->sum('amount'),
            'salidas'  => (float) $efectivo->where('type', 'out')->sum('amount'),
        ];
        $calculado = round((float) $sesion->opening_float + $desglose['cobros'] + $desglose['entradas'] - $desglose['salidas'], 2);
        $abierto = $sesion->closed_at === null;
    @endphp
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
            @if ($porMedio->count() > 1)
                <tr>
                    <td><strong>Total cobrado</strong></td>
                    <td class="text-right"><strong>{{ $porMedio->sum('cantidad') }}</strong></td>
                    <td class="text-right"><strong>₡{{ number_format((float) $porMedio->sum('total'), 2) }}</strong></td>
                </tr>
            @endif
        </tbody>
    </table>
    <div style="font-size:9px;color:#4b5563;margin-top:3px;">Solo el efectivo entra a la gaveta: tarjeta, SINPE y transferencia no se cuentan en el arqueo.</div>

    <h2>Movimientos del turno</h2>
    <table class="data">
        <thead>
            <tr><th>{{ $variosDias ? 'Fecha' : 'Hora' }}</th><th>Tipo</th><th>Referencia</th><th>Medio</th><th class="text-right">Monto</th></tr>
        </thead>
        <tbody>
            @forelse ($sesion->movements as $m)
                <tr>
                    {{-- Un turno que dura varios días con solo la hora parece
                         desordenado: 09:04 viene después de 22:25 porque es otro día. --}}
                    <td>{{ $m->happened_at?->format($variosDias ? 'd/m H:i' : 'H:i') }}</td>
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

    {{-- De dónde sale el esperado, para poder rehacer la cuenta a mano. --}}
    <table class="resumen">
        <tr><td>Fondo inicial</td><td>₡{{ number_format((float) $sesion->opening_float, 2) }}</td></tr>
        <tr><td>+ Cobros en efectivo</td><td>₡{{ number_format($desglose['cobros'], 2) }}</td></tr>
        @if ($desglose['entradas'] > 0)
            <tr><td>+ Entradas</td><td>₡{{ number_format($desglose['entradas'], 2) }}</td></tr>
        @endif
        @if ($desglose['salidas'] > 0)
            <tr><td>− Salidas</td><td>−₡{{ number_format($desglose['salidas'], 2) }}</td></tr>
        @endif
        @if ($abierto)
            {{-- El esperado se guarda al cerrar: con el turno abierto se calcula
                 acá, y no hay contado ni diferencia que mostrar todavía. --}}
            <tr class="total"><td>Efectivo esperado a hoy</td><td>₡{{ number_format($calculado, 2) }}</td></tr>
        @else
            <tr><td>Efectivo esperado</td><td>₡{{ number_format((float) $sesion->expected_cash, 2) }}</td></tr>
            <tr><td>Efectivo contado</td><td>₡{{ number_format((float) $sesion->counted_cash, 2) }}</td></tr>
            <tr class="total">
                <td>{{ (float) $sesion->discrepancy < 0 ? 'Faltante' : ((float) $sesion->discrepancy > 0 ? 'Sobrante' : 'Diferencia') }}</td>
                <td class="{{ abs((float) $sesion->discrepancy) < 0.01 ? 'dif-ok' : 'dif-mal' }}">
                    ₡{{ number_format(abs((float) $sesion->discrepancy), 2) }}
                </td>
            </tr>
        @endif
    </table>

    @if ($abierto)
        <div style="margin-top:10px;color:#a1352a;font-weight:bold;">Turno abierto: todavía no tiene arqueo.</div>
    @elseif (abs($calculado - (float) $sesion->expected_cash) >= 0.01)
        {{-- Los movimientos ya no suman lo que se esperaba al cerrar: algo se
             tocó después del arqueo. Se avisa en vez de esconderlo. --}}
        <div style="margin-top:10px;color:#a1352a;font-weight:bold;">
            Atención: los movimientos suman ₡{{ number_format($calculado, 2) }}, pero al cerrar se esperaban
            ₡{{ number_format((float) $sesion->expected_cash, 2) }}. Revisar qué cambió después del cierre.
        </div>
    @endif

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
