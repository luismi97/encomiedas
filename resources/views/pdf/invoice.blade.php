<html>
<head>
<meta charset="utf-8">
<style>
    /*
       DomPDF no soporta ni flexbox ni degradados. Todo el layout va con <table>
       y los fondos son colores solidos: un degradado no pinta nada y dejaba el
       texto blanco del encabezado sobre papel blanco, es decir, invisible.
    */
    @page { margin: 28px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }

    .header { background-color: #1d4ed8; color: #ffffff; padding: 14px 18px; }
    .header h1 { margin: 0 0 3px 0; font-size: 18px; color: #ffffff; }
    .header .muted { color: #dbeafe; font-size: 10px; }

    .doc-title { font-size: 16px; font-weight: bold; color: #111827; }
    .doc-date { color: #4b5563; font-size: 10px; }
    .badge {
        display: inline-block; padding: 3px 9px; background-color: #e5e7eb;
        border: 1px solid #9ca3af; color: #111827; font-size: 10px; font-weight: bold;
    }

    .boxes { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin-top: 14px; }
    .boxes td { width: 33.33%; border: 1px solid #9ca3af; padding: 9px; vertical-align: top; line-height: 1.45; }
    .boxes h3 {
        margin: 0 0 5px 0; font-size: 10px; text-transform: uppercase;
        letter-spacing: .4px; color: #1d4ed8; border-bottom: 1px solid #d1d5db; padding-bottom: 3px;
    }

    .items { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .items th {
        background-color: #1f2937; color: #ffffff; padding: 7px 8px;
        text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .3px;
    }
    .items td { padding: 6px 8px; border-bottom: 1px solid #d1d5db; }
    .items tbody tr:nth-child(even) td { background-color: #f3f4f6; }
    /* .items th gana en especificidad sobre .text-right, hay que reforzarlo
       o el encabezado de Precio queda a la izquierda y los montos a la derecha. */
    .text-right, .items th.text-right { text-align: right; }

    .totals { width: 44%; margin-top: 12px; margin-left: auto; border-collapse: collapse; }
    .totals td { padding: 4px 8px; }
    .totals td.label { text-align: right; color: #374151; }
    .totals td.value { text-align: right; width: 110px; }
    .totals tr.grand td {
        border-top: 2px solid #1f2937; font-weight: bold; font-size: 13px;
        color: #111827; padding-top: 6px;
    }

    .notes { margin-top: 14px; border-left: 3px solid #9ca3af; padding: 2px 0 2px 9px; }

    .legal { margin-top: 16px; font-size: 9px; color: #374151; border-top: 1px solid #9ca3af; padding-top: 7px; line-height: 1.5; }
    .legal strong { color: #111827; }
    .clave { font-family: DejaVu Sans Mono, monospace; font-size: 9px; word-wrap: break-word; color: #111827; }
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

    <table style="width:100%; margin-top: 14px; border-collapse: collapse;">
        <tr>
            <td style="vertical-align: top;">
                <div class="doc-title">Factura de encomienda {{ $invoice->code }}</div>
                <div class="doc-date">Fecha de emisión: {{ $invoice->created_at->format('d/m/Y H:i') }}</div>
            </td>
            <td style="vertical-align: top; text-align: right;">
                <span class="badge">{{ $invoice->statusLabel() }}</span>
            </td>
        </tr>
    </table>

    <table class="boxes">
      <tr>
        <td>
            <h3>Remitente</h3>
            {{ $invoice->sender_name }}<br>
            @if ($invoice->sender_identification) Identificación: {{ $invoice->sender_identification }}<br> @endif
            @if ($invoice->sender_phone) Tel: {{ $invoice->sender_phone }} @endif
            <br>Sucursal de recogida: <strong>{{ $invoice->pickupBranch->name }}</strong>
            @if ($invoice->pickupBranch->address) <br>{{ $invoice->pickupBranch->address }} @endif
        </td>
        <td>
            <h3>Receptor</h3>
            {{ $invoice->recipient_name }}<br>
            @if ($invoice->recipient_identification) Identificación ({{ $invoice->recipient_identification_type }}): {{ $invoice->recipient_identification }}<br> @endif
            @if ($invoice->recipient_phone) Tel: {{ $invoice->recipient_phone }}<br> @endif
            @if ($invoice->recipient_email) {{ $invoice->recipient_email }} @endif
            <br>Sucursal de entrega: <strong>{{ $invoice->deliveryBranch->name }}</strong>
            @if ($invoice->deliveryBranch->address) <br>{{ $invoice->deliveryBranch->address }} @endif
        </td>
        <td>
            <h3>Encomienda</h3>
            Condición de venta: {{ \App\Services\Hacienda\Catalogs::saleConditionLabel() }}<br>
            Comprobante: {{ $invoice->billTypeLabel() }}<br>
            Moneda: Colones (CRC)<br>
            Creada por: {{ $invoice->creator?->name ?? '—' }}<br>
            Repartidor asignado: {{ $invoice->assignedTo?->name ?? '—' }}<br>
            @if ($invoice->delivered_at) Entregada: {{ $invoice->delivered_at->format('d/m/Y H:i') }}<br> @endif
            @if ($invoice->returned_at) Devuelta: {{ $invoice->returned_at->format('d/m/Y H:i') }} @endif
        </td>
      </tr>
    </table>

    <table class="items">
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
                    <td>{{ $item->package_code ?: '—' }}</td>
                    <td>{{ $item->size ?: '—' }}</td>
                    <td>{{ $item->weight ? number_format($item->weight, 2) . ' kg' : '—' }}</td>
                    <td>{{ $item->description ?: '—' }}</td>
                    <td class="text-right">₡{{ number_format($item->price, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="value">₡{{ number_format($invoice->subtotal, 2) }}</td>
        </tr>
        @if ($invoice->discount_amount > 0)
            <tr>
                <td class="label">Descuento</td>
                <td class="value">-₡{{ number_format($invoice->discount_amount, 2) }}</td>
            </tr>
        @endif
        @foreach ($invoice->taxes as $tax)
            <tr>
                <td class="label">{{ $tax->name }} ({{ number_format($tax->percent, 2) }}%)</td>
                <td class="value">₡{{ number_format($tax->amount, 2) }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td class="label">Total</td>
            <td class="value">₡{{ number_format($invoice->total, 2) }}</td>
        </tr>
    </table>

    @if ($invoice->notes)
        <div class="notes"><strong>Notas:</strong> {{ $invoice->notes }}</div>
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
