<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Factura {{ $guia->code }}</title>
@include('recibo._estilos')
</head>
<body onload="window.print()">

{{-- La factura en rollo. La de A4 (pdf.invoice) sigue para descargar o mandar
     por correo, pero en el mostrador se imprime en la misma impresora del
     recibo, y un A4 encogido a 76 mm no lo lee nadie. Lleva lo mismo que la de
     A4: identificaciones, precio por bulto, cada impuesto con su tasa y los
     datos del comprobante electrónico. --}}

    <div class="centro">
        <div class="medio">{{ $empresa->commercial_name ?: $empresa->name }}</div>
        @if ($empresa->commercial_name && $empresa->name && $empresa->commercial_name !== $empresa->name)
            <div>{{ $empresa->name }}</div>
        @endif
        @if ($empresa->identification_number)
            <div>Céd. {{ $empresa->identification_number }}</div>
        @endif
        @if ($empresa->phone)
            <div>Tel. {{ $empresa->phone }}</div>
        @endif
        @if ($empresa->email)
            <div>{{ $empresa->email }}</div>
        @endif
    </div>

    <div class="regla"></div>

    <div class="centro">
        <div class="etiqueta">Factura de encomienda</div>
        <div class="grande">{{ $guia->code }}</div>
        <div>{{ $guia->created_at->format('d/m/Y H:i') }}</div>
        @if ($guia->creator)
            <div>Atendido por: {{ $guia->creator->name }}</div>
        @endif
    </div>

    <div class="regla"></div>

    {{-- Cuando no se factura al destinatario, el receptor fiscal es otro y
         tiene que verse quién: es el nombre que va en el comprobante. --}}
    @if ($guia->receptorIdentificado() && $guia->bill_to !== \App\Models\Invoice::BILL_TO_RECIPIENT)
        @php $fiscal = $guia->receptorDeFactura(); @endphp
        <div>
            <div class="etiqueta">Facturado a</div>
            <div class="medio">{{ $fiscal['nombre'] }}</div>
            <div>Id. ({{ $fiscal['tipo'] }}) {{ $fiscal['numero'] }}</div>
            @if ($fiscal['email'])<div class="clave">{{ $fiscal['email'] }}</div>@endif
        </div>

        <div class="regla"></div>
    @endif

    <div>
        <div class="etiqueta">Remitente</div>
        <div>{{ $guia->sender_name }}</div>
        @if ($guia->sender_identification)<div>Id. {{ $guia->sender_identification }}</div>@endif
        @if ($guia->sender_phone)<div>Tel. {{ $guia->sender_phone }}</div>@endif
        <div>Recoge: {{ $guia->pickupBranch?->name }}</div>
    </div>

    <div class="regla"></div>

    <div>
        <div class="etiqueta">Receptor</div>
        <div class="medio">{{ $guia->recipient_name }}</div>
        @if ($guia->recipient_identification)
            <div>Id. ({{ $guia->recipient_identification_type }}) {{ $guia->recipient_identification }}</div>
        @endif
        @if ($guia->recipient_phone)<div>Tel. {{ $guia->recipient_phone }}</div>@endif
        @if ($guia->recipient_email)<div class="clave">{{ $guia->recipient_email }}</div>@endif
        <div>Entrega: {{ $guia->deliveryBranch?->name }}</div>
        @if ($guia->esADomicilio())
            <div>A domicilio: {{ $guia->delivery_address }}</div>
        @endif
    </div>

    <div class="regla"></div>

    <div class="etiqueta">Detalle</div>
    @forelse ($guia->items as $item)
        <table class="fila"><tr>
            <td>
                {{ $item->nombreConCantidad() }}@if ($item->size) · {{ $item->size }}@endif
                @if ($item->weight) · {{ number_format((float) $item->weight, 2) }} kg @endif
            </td>
            <td>{{ number_format((float) $item->price, 2) }}</td>
        </tr></table>
        @if ($item->description)
            <div class="nota">{{ $item->description }}</div>
        @endif
    @empty
        <div>Sin paquetes registrados</div>
    @endforelse

    <div class="regla"></div>

    <table class="fila">
        <tr><td>Bultos</td><td>{{ number_format((float) $guia->subtotal, 2) }}</td></tr>
        @if ((float) $guia->insurance_fee > 0)
            <tr>
                <td>Seguro (declarado {{ number_format((float) $guia->declared_value, 2) }})</td>
                <td>{{ number_format((float) $guia->insurance_fee, 2) }}</td>
            </tr>
        @endif
        @if ((float) $guia->home_delivery_fee > 0)
            <tr><td>Entrega a domicilio</td><td>{{ number_format((float) $guia->home_delivery_fee, 2) }}</td></tr>
        @endif
        @if ((float) $guia->discount_amount > 0)
            <tr><td>Descuento</td><td>-{{ number_format((float) $guia->discount_amount, 2) }}</td></tr>
        @endif
        {{-- Cada impuesto con su tasa, como en la de A4: es un comprobante, no
             un recibo, y el cliente puede necesitar el desglose. --}}
        @forelse ($guia->taxes as $impuesto)
            <tr>
                <td>{{ $impuesto->name }} ({{ number_format((float) $impuesto->percent, 2) }}%)</td>
                <td>{{ number_format((float) $impuesto->amount, 2) }}</td>
            </tr>
        @empty
            <tr><td>Impuesto</td><td>{{ number_format((float) $guia->tax_total, 2) }}</td></tr>
        @endforelse
        <tr class="grande"><td>TOTAL</td><td>₡{{ number_format((float) $guia->total, 2) }}</td></tr>
    </table>

    <div style="margin-top: 1mm;">
        {{ $guia->saleConditionLabel() }}@if ($guia->medioDePagoImpreso() !== '') · {{ $guia->medioDePagoImpreso() }}@endif
    </div>
    <div>Comprobante: {{ $guia->billTypeLabel() }} · Colones (CRC)</div>

    @if ($guia->tieneCobroPendiente())
        <div class="centro" style="border:2px solid #000;padding:1.5mm;margin-top:2mm;font-weight:bold">
            POR COBRAR AL ENTREGAR
        </div>
    @elseif ($guia->esperandoCaja())
        {{-- Lo imprimió quien recibió el paquete, antes de pasar por caja. --}}
        <div class="centro" style="border:2px solid #000;padding:1.5mm;margin-top:2mm;margin-bottom:2mm;font-weight:bold">
            PENDIENTE DE PAGO EN CAJA
        </div>
    @elseif ($guia->esCredito())
        <div class="centro" style="border:1px solid #000;padding:1mm;margin-top:2mm;font-weight:bold">
            A CRÉDITO · NO SE COBRÓ EN CAJA
        </div>
    @endif

    @if ($guia->electronicInvoice)
        <div class="regla"></div>
        <div class="etiqueta">Comprobante electrónico</div>
        <div>{{ $guia->electronicInvoice->typeLabel() }} · {{ $guia->electronicInvoice->statusLabel() }}</div>
        <div class="etiqueta" style="margin-top: 1mm;">Consecutivo</div>
        <div class="clave">{{ $guia->electronicInvoice->consecutivo }}</div>
        {{-- La clave lleva la fecha de emisión, que es la del envío. --}}
        @if ($guia->electronicInvoice->claveEsDefinitiva())
            <div class="etiqueta" style="margin-top: 1mm;">Clave</div>
            <div class="clave nota">{{ $guia->electronicInvoice->clave }}</div>
        @endif
    @endif

    @if ($guia->notes)
        <div class="regla"></div>
        <div class="etiqueta">Notas</div>
        <div class="nota">{{ $guia->notes }}</div>
    @endif

    <div class="centro chico" style="margin-top: 3mm;">
        Consérvela como constancia de la transacción
        (Ley de Promoción de la Competencia y Defensa Efectiva del Consumidor).
    </div>

    <div class="no-imprimir centro" style="margin-top: 6mm;">
        <button type="button" onclick="window.print()"
                style="padding: 8px 16px; font-size: 13px; font-family: system-ui, sans-serif; cursor: pointer;">
            Imprimir de nuevo
        </button>
        <div style="margin-top: 3mm; font-family: system-ui, sans-serif; font-size: 12px; font-weight: normal;">
            <a href="{{ route('invoices.pdf', $guia) }}">Descargar en PDF tamaño A4</a>
        </div>
    </div>

</body>
</html>
