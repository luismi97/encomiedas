<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $guia->code }}</title>
@include('recibo._estilos')
</head>
<body onload="window.print()">

    <div class="centro">
        <div class="medio">{{ $empresa->commercial_name ?: $empresa->name }}</div>
        @if ($empresa->identification_number)
            <div>Céd. {{ $empresa->identification_number }}</div>
        @endif
        @if ($empresa->phone)
            <div>Tel. {{ $empresa->phone }}</div>
        @endif
    </div>

    <div class="regla"></div>

    @if (($copia ?? null) && $copia->esReimpresion())
        <div class="centro" style="border: 2px solid #000; padding: 1mm; margin-bottom: 2mm; font-weight: bold;">
            REIMPRESIÓN · COPIA {{ $copia->copy_number }}
            <div class="chico fino">{{ $copia->created_at->format('d/m/Y H:i') }}</div>
        </div>
    @endif

    <div class="centro">
        <div class="etiqueta">Código de guía</div>
        <div class="grande">{{ $guia->code }}</div>
        <div>{{ $guia->created_at->format('d/m/Y H:i') }}</div>
        @if ($guia->creator)
            <div>Atendido por: {{ $guia->creator->name }}</div>
        @endif
        @if ($guia->offline_reference)
            {{-- El cliente atendido sin conexión se fue con ese número: así
                 se relaciona el papel provisional con esta guía. --}}
            <div class="nota">Comprobante provisional: {{ $guia->offline_reference }}</div>
        @endif
    </div>

    <div class="centro qr" style="margin: 2mm 0;">
        <img src="{{ $qr }}" alt="QR {{ $guia->code }}">
        <div class="chico">Escanee para seguir su encomienda</div>
    </div>

    <div class="regla"></div>

    <div>
        <div class="etiqueta">Remitente</div>
        <div>{{ $guia->sender_name }}</div>
        @if ($guia->sender_phone)<div>{{ $guia->sender_phone }}</div>@endif
        <div>{{ $guia->pickupBranch?->name }}</div>
    </div>

    <div class="regla"></div>

    <div>
        <div class="etiqueta">Destinatario</div>
        <div class="medio">{{ $guia->recipient_name }}</div>
        @if ($guia->recipient_phone)<div>{{ $guia->recipient_phone }}</div>@endif
        <div class="medio">{{ $guia->deliveryBranch?->name }}</div>
    </div>

    <div class="regla"></div>

    @if ($guia->tieneCobroPendiente())
        <div class="centro" style="border:2px solid #000;padding:1.5mm;margin-bottom:2mm;font-weight:bold">
            POR COBRAR AL ENTREGAR
            <div class="grande">₡{{ number_format((float) $guia->total, 2) }}</div>
        </div>
    @elseif ($guia->esperandoCaja())
        {{-- Lo imprimió quien recibió el paquete, antes de pasar por caja. --}}
        <div class="centro" style="border:2px solid #000;padding:1.5mm;margin-top:2mm;margin-bottom:2mm;font-weight:bold">
            PENDIENTE DE PAGO EN CAJA
        </div>
    @elseif ($guia->esCredito())
        <div class="centro" style="border:1px solid #000;padding:1mm;margin-bottom:2mm;font-weight:bold">
            A CRÉDITO · NO SE COBRÓ EN CAJA
        </div>
    @endif

    <div>
    @if ($guia->esADomicilio())
        <div class="regla"></div>
        <div class="etiqueta">Entrega a domicilio</div>
        <div>{{ $guia->delivery_address }}</div>
    @endif

        <div class="etiqueta">Paquetes</div>
        @forelse ($guia->items as $item)
            <table class="fila"><tr>
                <td>{{ $item->nombreConCantidad() }}@if ($item->size) · {{ $item->size }}@endif</td>
                <td>{{ $item->weight ? number_format((float) $item->weight, 2) . ' kg' : '' }}</td>
            </tr></table>
            @if ($item->description)
                <div class="nota">{{ $item->description }}</div>
            @endif
        @empty
            <div>Sin paquetes registrados</div>
        @endforelse
    </div>

    <div class="regla"></div>

    <table class="fila">
        <tr><td>Bultos</td><td>{{ number_format((float) $guia->subtotal, 2) }}</td></tr>
        {{-- Desglosado: el cliente tiene derecho a ver por qué paga cada cosa,
             y un cargo sin explicar es la primera fuente de reclamos. --}}
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
        @if ($exo = $guia->exoneracion())
            <tr><td>IVA exonerado {{ $exo['numero'] }}</td><td>-{{ number_format((float) $guia->exempt_tax_amount, 2) }}</td></tr>
        @endif
        <tr><td>Impuesto</td><td>{{ number_format((float) $guia->tax_total, 2) }}</td></tr>
        <tr class="grande"><td>TOTAL</td><td>{{ number_format((float) $guia->total, 2) }}</td></tr>
        <tr><td>{{ $guia->saleConditionLabel() }}</td><td>{{ $guia->medioDePagoImpreso() }}</td></tr>
        @if ((float) $guia->declared_value > 0)
            <tr><td>Valor declarado</td><td>{{ number_format((float) $guia->declared_value, 2) }}</td></tr>
        @endif
    </table>

    {{-- Con qué comprobante se declaró el servicio: el cliente lo busca por
         la clave en su contabilidad y en el correo que le llega de Hacienda.
         Solo si ya existe y no fue rechazado: una clave rechazada se rehace
         con otra y la impresa no serviría para nada. --}}
    @php $comprobante = $guia->electronicInvoice; @endphp
    @if ($comprobante && ! $comprobante->wasRejected())
        <div class="regla"></div>
        <div class="etiqueta">{{ $comprobante->typeLabel() }}</div>
        <div class="etiqueta" style="margin-top: 1mm;">Consecutivo</div>
        <div class="clave">{{ $comprobante->consecutivo }}</div>
        <div class="etiqueta" style="margin-top: 1mm;">Clave numérica</div>
        <div class="clave nota">{{ $comprobante->clave }}</div>
    @endif

    <div class="firma">Recibí conforme · nombre, cédula y firma</div>

    <div class="centro chico" style="margin-top: 3mm;">
        Consérvelo: es el comprobante de su encomienda.
    </div>

    <div class="no-imprimir centro" style="margin-top: 6mm;">
        <button type="button" onclick="window.print()"
                style="padding: 8px 16px; font-size: 13px; font-family: system-ui, sans-serif; cursor: pointer;">
            Imprimir de nuevo
        </button>
    </div>

</body>
</html>
