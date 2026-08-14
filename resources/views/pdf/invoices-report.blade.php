<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
    h1 { font-size: 16px; margin-bottom: 2px; }
    .muted { color: #6b7280; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: 5px 6px; text-align: left; }
    th { background: #f3f4f6; }
    .text-right { text-align: right; }
    .totals { margin-top: 10px; font-size: 12px; text-align: right; }
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
