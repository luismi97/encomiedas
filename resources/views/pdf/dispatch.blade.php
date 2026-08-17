<html>
<head>
<meta charset="utf-8">
<style>
    /* Misma paleta que los otros PDF: DomPDF no soporta flexbox ni degradados. */
    @page { margin: 28px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
    .header { background-color: #1d4ed8; color: #ffffff; padding: 14px 18px; }
    .header h1 { margin: 0 0 3px 0; font-size: 18px; color: #ffffff; }
    .header .muted { color: #dbeafe; font-size: 10px; }
    .meta { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin-top: 14px; }
    .meta td { width: 25%; border: 1px solid #9ca3af; padding: 8px; vertical-align: top; }
    .meta .label { font-size: 9px; text-transform: uppercase; letter-spacing: .4px; color: #1d4ed8; display: block; margin-bottom: 3px; }
    .items { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .items th { background-color: #1f2937; color: #fff; padding: 7px 8px; text-align: left; font-size: 10px; text-transform: uppercase; }
    .items td { padding: 6px 8px; border-bottom: 1px solid #d1d5db; }
    .items tbody tr:nth-child(even) td { background-color: #f3f4f6; }
    .text-right, .items th.text-right { text-align: right; }
    .totals { width: 100%; margin-top: 12px; border-top: 2px solid #1f2937; padding-top: 6px; font-weight: bold; }
    .firmas { width: 100%; margin-top: 42px; border-collapse: separate; border-spacing: 18px 0; }
    .firmas td { width: 50%; border-top: 1px solid #111827; padding-top: 6px; text-align: center; font-size: 10px; color: #374151; }
    .legal { margin-top: 18px; font-size: 9px; color: #374151; border-top: 1px solid #9ca3af; padding-top: 7px; }
</style>
</head>
<body>
    <div class="header">
        <h1>Cierre de envío {{ $dispatch->code }}</h1>
        <div class="muted">{{ $company->commercial_name ?: $company->name }} · Manifiesto de transporte de encomiendas</div>
    </div>

    <table class="meta">
        <tr>
            <td><span class="label">Ruta</span>{{ $dispatch->originBranch?->name }}<br>→ {{ $dispatch->destinationBranch?->name }}</td>
            <td><span class="label">Chofer</span>{{ $dispatch->driver_name ?: '—' }}<br>{{ $dispatch->vehicle_plate ?: '' }}</td>
            <td><span class="label">Salida</span>{{ $dispatch->departed_at?->format('d/m/Y H:i') ?: 'Sin despachar' }}</td>
            <td><span class="label">Generado</span>{{ now()->format('d/m/Y H:i') }}<br>{{ $dispatch->creator?->name }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Código guía</th>
                <th>Destinatario</th>
                <th>Sede destino</th>
                <th class="text-right">Paquetes</th>
                <th class="text-right">Peso</th>
                <th class="text-right">Valor decl.</th>
                <th>Recibido</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($dispatch->lines as $linea)
                @php $guia = $linea->invoice; @endphp
                <tr>
                    <td>{{ $guia?->code }}</td>
                    <td>{{ $guia?->recipient_name }}</td>
                    <td>{{ $guia?->deliveryBranch?->name }}</td>
                    <td class="text-right">{{ $guia?->items->count() }}</td>
                    <td class="text-right">{{ number_format((float) $guia?->items->sum('weight'), 2) }} kg</td>
                    <td class="text-right">₡{{ number_format((float) $guia?->declared_value, 2) }}</td>
                    <td>{{ $linea->received_at ? 'Sí' : ($linea->incident === 'faltante' ? 'FALTANTE' : '☐') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        {{ $dispatch->lines->count() }} guía(s) · {{ $dispatch->totalPaquetes() }} paquete(s) ·
        {{ $dispatch->pesoTotal() }} kg · valor declarado ₡{{ number_format($dispatch->valorDeclaradoTotal(), 2) }}
    </div>

    @if ($dispatch->notes)
        <div style="margin-top:12px;"><strong>Notas:</strong> {{ $dispatch->notes }}</div>
    @endif

    <table class="firmas">
        <tr>
            <td>Firma del chofer<br>{{ $dispatch->driver_name ?: '' }}</td>
            <td>Recibido en sede destino<br>Nombre, cédula y fecha</td>
        </tr>
    </table>

    <div class="legal">
        Este manifiesto ampara el traslado de las encomiendas listadas. Quien recibe en destino debe verificar
        la cantidad de bultos contra este detalle y anotar cualquier faltante o daño antes de firmar.
    </div>
</body>
</html>
