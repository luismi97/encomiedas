<html>
<head>
<meta charset="utf-8">
<style>
    /* Misma paleta que pdf/invoices-report.blade.php. */
    @page { margin: 28px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    h2 { font-size: 12px; margin: 16px 0 6px; }
    .muted { color: #374151; }
    table { width: 100%; border-collapse: collapse; }
    th {
        background-color: #1f2937; color: #ffffff; padding: 5px;
        text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .3px;
    }
    td { border-bottom: 1px solid #d1d5db; padding: 4px 5px; text-align: left; }
    tbody tr:nth-child(even) td { background-color: #f3f4f6; }
    .text-right, th.text-right { text-align: right; }
    .nowrap { white-space: nowrap; }
    tfoot td { font-weight: bold; border-top: 2px solid #1f2937; border-bottom: none; }
    .destacado { margin-top: 10px; border: 2px solid #1f2937; padding: 8px; font-size: 12px; }
    .destacado strong { font-size: 14px; }
    .aviso { margin-top: 8px; padding: 6px; border: 1px solid #b45309; color: #92400e; }
    .clave { font-size: 7px; color: #374151; }
</style>
</head>
<body>
    <h1>Reporte contable de ventas e IVA</h1>
    <div class="muted">
        {{ $r['empresa'] }}@if ($r['cedula']) · Cédula {{ $r['cedula'] }}@endif<br>
        Período (fecha de emisión): {{ $r['desde'] }} al {{ $r['hasta'] }}
        @if ($r['sede']) · Sede: {{ $r['sede'] }}@endif
        · Generado: {{ now()->format('d/m/Y H:i') }}
    </div>
    <div class="muted" style="margin-top:4px">
        Solo comprobantes <strong>aceptados por Hacienda</strong> en producción. Las notas de crédito restan.
    </div>

    <div class="destacado">
        Ventas netas: <strong>₡{{ number_format($r['neto']['venta'], 2) }}</strong>
        &nbsp;·&nbsp; IVA a declarar: <strong>₡{{ number_format($r['neto']['iva'], 2) }}</strong>
        &nbsp;·&nbsp; Total: <strong>₡{{ number_format($r['neto']['total'], 2) }}</strong>
    </div>

    @if ($r['pendientes'] > 0)
        <div class="aviso">
            Hay {{ $r['pendientes'] }} comprobante(s) del período todavía sin respuesta de Hacienda: no están incluidos.
            Si Hacienda los acepta, este reporte cambia.
        </div>
    @endif

    <h2>Por tipo de comprobante</h2>
    <table>
        <thead>
            <tr><th>Tipo</th><th class="text-right">Cantidad</th><th class="text-right">Venta neta</th><th class="text-right">IVA</th><th class="text-right">Total</th></tr>
        </thead>
        <tbody>
            @forelse ($r['porTipo'] as $fila)
                <tr>
                    <td>{{ $fila['tipo'] }}</td>
                    <td class="text-right">{{ $fila['cantidad'] }}</td>
                    <td class="text-right nowrap">₡{{ number_format($fila['venta'], 2) }}</td>
                    <td class="text-right nowrap">₡{{ number_format($fila['iva'], 2) }}</td>
                    <td class="text-right nowrap">₡{{ number_format($fila['total'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No hay comprobantes aceptados en el período.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Neto</td>
                <td class="text-right">{{ collect($r['porTipo'])->sum('cantidad') }}</td>
                <td class="text-right nowrap">₡{{ number_format($r['neto']['venta'], 2) }}</td>
                <td class="text-right nowrap">₡{{ number_format($r['neto']['iva'], 2) }}</td>
                <td class="text-right nowrap">₡{{ number_format($r['neto']['total'], 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2>Por tarifa de IVA</h2>
    <table>
        <thead><tr><th>Tarifa</th><th class="text-right">Base (venta neta)</th><th class="text-right">IVA</th></tr></thead>
        <tbody>
            @forelse ($r['porTarifa'] as $fila)
                <tr>
                    <td>{{ $fila['tarifa'] }}</td>
                    <td class="text-right nowrap">₡{{ number_format($fila['venta'], 2) }}</td>
                    <td class="text-right nowrap">₡{{ number_format($fila['iva'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">—</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Detalle de comprobantes</h2>
    <table>
        <thead>
            <tr>
                <th>Fecha</th><th>Tipo</th><th>Consecutivo / clave</th><th>Receptor</th>
                <th class="text-right">Venta neta</th><th class="text-right">IVA</th><th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($r['detalle'] as $fila)
                <tr>
                    <td class="nowrap">{{ $fila['fecha'] }}</td>
                    <td>{{ $fila['tipoNombre'] }}</td>
                    <td>{{ $fila['consecutivo'] }}<div class="clave">{{ $fila['clave'] }}</div></td>
                    <td>{{ $fila['receptor'] }}@if ($fila['cedula'])<br><span class="muted">{{ $fila['cedula'] }}</span>@endif</td>
                    <td class="text-right nowrap">₡{{ number_format($fila['venta'], 2) }}</td>
                    <td class="text-right nowrap">₡{{ number_format($fila['iva'], 2) }}</td>
                    <td class="text-right nowrap">₡{{ number_format($fila['total'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
