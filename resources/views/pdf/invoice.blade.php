<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
    .header { background: linear-gradient(135deg, #2563eb 0%, #1e3a8a 100%); color: #fff; padding: 14px 18px; border-radius: 8px; }
    .header h1 { margin: 0; font-size: 18px; }
    .header .muted { color: #dbeafe; font-size: 11px; }
    .grid { display: flex; gap: 18px; margin-top: 14px; }
    .box { flex: 1; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; }
    .box h3 { margin: 0 0 6px 0; font-size: 12px; text-transform: uppercase; color: #6b7280; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
    th { background: #f3f4f6; }
    .text-right { text-align: right; }
    .totals { margin-top: 10px; width: 280px; margin-left: auto; }
    .totals div { display: flex; justify-content: space-between; padding: 3px 0; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #f3f4f6; font-size: 10px; }
    .legal { margin-top: 18px; font-size: 9px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 8px; }
    .clave { font-size: 9px; word-break: break-all; color: #6b7280; }
</style>
</head>
<body>
    <div class="header">
        <h1>{{ $company->commercial_name ?: $company->name ?: config('app.name') }}</h1>
        <div class="muted">
            {{ $company->name }}
            @if ($company->identification_number) · Cédula: {{ $company->identification_number }} @endif
            @if ($company->phone) · Tel: {{ $company->phone }} @endif
            @if ($company->email) · {{ $company->email }} @endif
        </div>
    </div>

    <div style="margin-top: 14px; display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
            <div style="font-size: 16px; font-weight: bold;">Factura de encomienda {{ $invoice->code }}</div>
            <div class="muted" style="color:#6b7280;">Fecha de emisión: {{ $invoice->created_at->format('d/m/Y H:i') }}</div>
        </div>
        <span class="badge">{{ $invoice->statusLabel() }}</span>
    </div>

    <div class="grid">
        <div class="box">
            <h3>Remitente</h3>
            {{ $invoice->sender_name }}<br>
            @if ($invoice->sender_identification) Identificación: {{ $invoice->sender_identification }}<br> @endif
            @if ($invoice->sender_phone) Tel: {{ $invoice->sender_phone }} @endif
            <br>Sucursal de recogida: <strong>{{ $invoice->pickupBranch->name }}</strong>
            @if ($invoice->pickupBranch->address) <br>{{ $invoice->pickupBranch->address }} @endif
        </div>
        <div class="box">
            <h3>Receptor</h3>
            {{ $invoice->recipient_name }}<br>
            @if ($invoice->recipient_identification) Identificación ({{ $invoice->recipient_identification_type }}): {{ $invoice->recipient_identification }}<br> @endif
            @if ($invoice->recipient_phone) Tel: {{ $invoice->recipient_phone }}<br> @endif
            @if ($invoice->recipient_email) {{ $invoice->recipient_email }} @endif
            <br>Sucursal de entrega: <strong>{{ $invoice->deliveryBranch->name }}</strong>
            @if ($invoice->deliveryBranch->address) <br>{{ $invoice->deliveryBranch->address }} @endif
        </div>
        <div class="box">
            <h3>Encomienda</h3>
            Condición de venta: Contado<br>
            Moneda: Colones (CRC)<br>
            Creada por: {{ $invoice->creator?->name ?? '—' }}<br>
            Repartidor asignado: {{ $invoice->assignedTo?->name ?? '—' }}<br>
            @if ($invoice->delivered_at) Entregada: {{ $invoice->delivered_at->format('d/m/Y H:i') }}<br> @endif
            @if ($invoice->returned_at) Devuelta: {{ $invoice->returned_at->format('d/m/Y H:i') }} @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Código de paquete</th>
                <th>Tamaño</th>
                <th>Peso</th>
                <th>Descripción</th>
                <th class="text-right">Precio</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->package_code }}</td>
                    <td>{{ $item->size }}</td>
                    <td>{{ $item->weight }} kg</td>
                    <td>{{ $item->description }}</td>
                    <td class="text-right">₡{{ number_format($item->price, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span>₡{{ number_format($invoice->subtotal, 2) }}</span></div>
        @if ($invoice->discount_amount > 0)
            <div><span>Descuento</span><span>-₡{{ number_format($invoice->discount_amount, 2) }}</span></div>
        @endif
        @foreach ($invoice->taxes as $tax)
            <div><span>{{ $tax->name }} ({{ number_format($tax->percent, 2) }}%)</span><span>₡{{ number_format($tax->amount, 2) }}</span></div>
        @endforeach
        <div style="font-weight:bold; border-top: 1px solid #e5e7eb; padding-top: 4px;"><span>Total</span><span>₡{{ number_format($invoice->total, 2) }}</span></div>
    </div>

    @if ($invoice->notes)
        <div style="margin-top: 12px;"><strong>Notas:</strong> {{ $invoice->notes }}</div>
    @endif

    @if ($invoice->electronicInvoice)
        <div class="legal">
            <strong>Comprobante electrónico (Ministerio de Hacienda, Costa Rica)</strong><br>
            Tipo: {{ $invoice->electronicInvoice->typeLabel() }} · Estado: {{ $invoice->electronicInvoice->statusLabel() }}<br>
            Consecutivo: {{ $invoice->electronicInvoice->consecutivo }}<br>
            Clave: <span class="clave">{{ $invoice->electronicInvoice->clave }}</span>
        </div>
    @endif

    <div class="legal">
        Documento generado por {{ $company->name ?: config('app.name') }}
        @if ($company->identification_number) , cédula jurídica {{ $company->identification_number }} @endif.
        Este comprobante respalda el servicio de transporte de encomienda descrito arriba. Consérvelo como constancia
        de la transacción, según la Ley de Promoción de la Competencia y Defensa Efectiva del Consumidor (Costa Rica).
    </div>
</body>
</html>
