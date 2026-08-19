<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $guia->code }}</title>
<style>
    /*
       Etiqueta para impresora térmica. Se imprime desde el navegador contra el
       driver del sistema: no hace falta WebUSB ni un puente local, y funciona
       igual en Windows, Mac y una tablet Android.

       El ancho sale de la sede, no de una constante: cada mostrador compra la
       impresora que consigue, de 58 o de 80 mm.
    */
    @page {
        size: {{ $ancho }}mm auto;
        margin: 0;
    }

    * { box-sizing: border-box; }

    body {
        width: {{ $ancho }}mm;
        margin: 0;
        padding: 3mm;
        /* Monoespaciada: en térmica es lo que sale parejo y legible. */
        font-family: "Courier New", ui-monospace, monospace;
        font-size: {{ $ancho >= 80 ? '11px' : '10px' }};
        line-height: 1.35;
        color: #000;
        background: #fff;
    }

    .centro { text-align: center; }
    .grande { font-size: {{ $ancho >= 80 ? '17px' : '14px' }}; font-weight: bold; letter-spacing: .5px; }
    .medio  { font-size: {{ $ancho >= 80 ? '13px' : '12px' }}; font-weight: bold; }
    .regla  { border-top: 1px dashed #000; margin: 2mm 0; }
    /* Tabla y no flex: la etiqueta también se renderiza a PDF en algunos
       flujos, y DomPDF ignora flexbox — los montos saldrían pegados. */
    .fila   { width: 100%; border-collapse: collapse; }
    .fila td:last-child { text-align: right; }
    .etiqueta { text-transform: uppercase; font-size: 9px; letter-spacing: .4px; }
    .qr img { width: {{ $ancho >= 80 ? '36mm' : '30mm' }}; height: auto; }
    .firma { margin-top: 8mm; border-top: 1px solid #000; padding-top: 1mm; text-align: center; font-size: 9px; }

    /* En pantalla se ve el papel; al imprimir, solo el contenido. */
    @media screen {
        body { margin: 20px auto; box-shadow: 0 0 0 1px #ddd; }
        .no-imprimir { display: block; }
    }
    @media print {
        .no-imprimir { display: none !important; }
    }
</style>
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
            <div style="font-size: 8px; font-weight: normal;">{{ $copia->created_at->format('d/m/Y H:i') }}</div>
        </div>
    @endif

    <div class="centro">
        <div class="etiqueta">Código de guía</div>
        <div class="grande">{{ $guia->code }}</div>
        <div>{{ $guia->created_at->format('d/m/Y H:i') }}</div>
    </div>

    <div class="centro qr" style="margin: 2mm 0;">
        <img src="{{ $qr }}" alt="QR {{ $guia->code }}">
        <div style="font-size: 8px;">Escanee para seguir su encomienda</div>
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
    @elseif ($guia->esCredito())
        <div class="centro" style="border:1px solid #000;padding:1mm;margin-bottom:2mm;font-weight:bold">
            A CRÉDITO · NO SE COBRÓ EN CAJA
        </div>
    @endif

    <div>
        <div class="etiqueta">Paquetes</div>
        @forelse ($guia->items as $item)
            <table class="fila"><tr>
                <td>{{ $item->nombreDelBulto() }}@if ($item->size) · {{ $item->size }}@endif</td>
                <td>{{ $item->weight ? number_format((float) $item->weight, 2) . ' kg' : '' }}</td>
            </tr></table>
            @if ($item->description)
                <div style="font-size: 9px;">{{ $item->description }}</div>
            @endif
        @empty
            <div>Sin paquetes registrados</div>
        @endforelse
    </div>

    <div class="regla"></div>

    <table class="fila">
        <tr><td>Subtotal</td><td>{{ number_format((float) $guia->subtotal, 2) }}</td></tr>
        @if ((float) $guia->discount_amount > 0)
            <tr><td>Descuento</td><td>-{{ number_format((float) $guia->discount_amount, 2) }}</td></tr>
        @endif
        <tr><td>Impuesto</td><td>{{ number_format((float) $guia->tax_total, 2) }}</td></tr>
        <tr class="grande"><td>TOTAL</td><td>{{ number_format((float) $guia->total, 2) }}</td></tr>
        <tr><td>{{ $guia->saleConditionLabel() }}</td><td>{{ \App\Models\Invoice::PAYMENT_METHODS[$guia->payment_method] ?? '' }}</td></tr>
        @if ((float) $guia->declared_value > 0)
            <tr><td>Valor declarado</td><td>{{ number_format((float) $guia->declared_value, 2) }}</td></tr>
        @endif
    </table>

    <div class="firma">Recibí conforme · nombre, cédula y firma</div>

    <div class="centro" style="margin-top: 3mm; font-size: 8px;">
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
