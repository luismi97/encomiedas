<html>
<head>
<meta charset="utf-8">
<style>
    /* Misma paleta que pdf/invoice.blade.php: los grises claros se lavan al imprimir. */
    @page { margin: 28px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
    h1 { font-size: 16px; margin-bottom: 2px; color: #111827; }
    .muted { color: #374151; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th {
        background-color: #1f2937; color: #ffffff; padding: 6px;
        text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .3px;
    }
    td { border-bottom: 1px solid #d1d5db; padding: 5px 6px; text-align: left; }
    tbody tr:nth-child(even) td { background-color: #f3f4f6; }
    /* Reforzado con el selector de elemento: dompdf no siempre resuelve la
       especificidad como el navegador y el encabezado quedaba desalineado. */
    .text-right, th.text-right { text-align: right; }
    /* El codigo de encomienda partido en dos lineas se lee mal. */
    td:first-child, th:first-child { white-space: nowrap; }
    .totals { margin-top: 12px; font-size: 12px; text-align: right; font-weight: bold; border-top: 2px solid #1f2937; padding-top: 6px; }
</style>
</head>
<body>
    <h1>Reporte de encomiendas</h1>
    <div class="muted">
        Periodo: {{ $from ?: '—' }} a {{ $to ?: '—' }} · Generado: {{ now()->format('d/m/Y H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Fecha</th>
                <th>Remitente</th>
                <th>Receptor</th>
                <th>Recogida</th>
                <th>Entrega</th>
                <th>Repartidor</th>
                <th>Estado</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoices as $invoice)
                <tr>
                    <td>{{ $invoice->code }}</td>
                    <td>{{ $invoice->created_at->format('d/m/Y') }}</td>
                    <td>{{ $invoice->sender_name }}</td>
                    <td>{{ $invoice->recipient_name }}</td>
                    <td>{{ $invoice->pickupBranch->name }}</td>
                    <td>{{ $invoice->deliveryBranch->name }}</td>
                    <td>{{ $invoice->assignedTo?->name ?? '—' }}</td>
                    <td>{{ $invoice->statusLabel() }}</td>
                    <td class="text-right">₡{{ number_format($invoice->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        Total del periodo: <strong>₡{{ number_format($total, 2) }}</strong> · {{ $invoices->count() }} factura(s)
    </div>
</body>
</html>
