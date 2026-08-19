<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Etiqueta {{ $guia->code }}</title>
<style>
    /*
       Etiqueta que se PEGA AL PAQUETE, distinta del recibo del cliente.

       Lo que manda acá es lo que se necesita ver con el bulto en la mano: la
       ruta, a quién va y el código de barras. El detalle de montos no va: la
       etiqueta queda a la vista de cualquiera que manipule el paquete.
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
        font-family: "Courier New", ui-monospace, monospace;
        font-size: {{ $ancho >= 80 ? '11px' : '10px' }};
        line-height: 1.3;
        color: #000;
        background: #fff;
    }

    .centro { text-align: center; }
    .regla { border-top: 1px dashed #000; margin: 1.5mm 0; }
    .etiqueta { text-transform: uppercase; font-size: 8px; letter-spacing: .4px; }

    /* La ruta es lo que lee el que carga el camión, de lejos y apurado. */
    .ruta {
        font-size: {{ $ancho >= 80 ? '26px' : '20px' }};
        font-weight: bold;
        letter-spacing: 1px;
        line-height: 1.1;
    }
    .destino-sede { font-size: {{ $ancho >= 80 ? '15px' : '13px' }}; font-weight: bold; }

    .persona { font-size: {{ $ancho >= 80 ? '14px' : '12px' }}; font-weight: bold; }

    /* El SVG se escala al ancho del papel; el alto lo fija el propio SVG. */
    .barras svg { width: 100%; height: auto; display: block; }
    .codigo {
        font-size: {{ $ancho >= 80 ? '15px' : '12px' }};
        font-weight: bold;
        letter-spacing: {{ $ancho >= 80 ? '2px' : '1px' }};
    }

    .bulto {
        border: 2px solid #000;
        padding: 1mm;
        font-size: {{ $ancho >= 80 ? '15px' : '13px' }};
        font-weight: bold;
    }

    .frag { border: 2px solid #000; padding: 1mm; margin-top: 1.5mm; font-weight: bold; }

    /* Cada etiqueta en su propia hoja de rollo: el corte va entre bultos. */
    .corte { page-break-after: always; }
    .corte:last-child { page-break-after: auto; }

    @media screen {
        body { margin: 20px auto; box-shadow: 0 0 0 1px #ddd; }
        .corte + .corte { margin-top: 8mm; border-top: 2px dashed #999; padding-top: 8mm; }
    }
    @media print {
        .no-imprimir { display: none !important; }
    }
</style>
</head>
<body onload="window.print()">

@foreach ($bultos as $indice => $bulto)
    <div class="corte">
        <div class="centro etiqueta">{{ $empresa->commercial_name ?: $empresa->name }}</div>

        <div class="regla"></div>

        <div class="centro">
            <div class="etiqueta">Destino</div>
            <div class="ruta">{{ $guia->deliveryBranch?->prefix }}</div>
            <div class="destino-sede">{{ $guia->deliveryBranch?->name }}</div>
        </div>

        <div class="regla"></div>

        {{-- El código de barras es el motivo de esta etiqueta: se escanea en
             recepción, en el despacho y en la entrega. --}}
        <div class="centro barras">{!! $barras !!}</div>
        <div class="centro codigo">{{ $guia->code }}</div>

        <div class="regla"></div>

        <div class="centro bulto">
            BULTO {{ $indice + 1 }} DE {{ count($bultos) }}
            @if ($bulto?->packageType)
                <div style="font-size: {{ $ancho >= 80 ? '13px' : '11px' }}">
                    {{ mb_strtoupper($bulto->packageType->name, "UTF-8") }}
                </div>
            @endif
        </div>

        {{-- El aviso va grande y con marco: la etiqueta la lee quien carga el
             camión, no quien digitó la guía. --}}
        @if ($bulto?->esFragil())
            <div class="centro frag">
                FRÁGIL · MANEJAR CON CUIDADO
            </div>
        @endif

        <div class="regla"></div>

        <div class="etiqueta">Destinatario</div>
        <div class="persona">{{ $guia->recipient_name }}</div>
        @if ($guia->recipient_phone)
            <div>Tel. {{ $guia->recipient_phone }}</div>
        @endif

        <div class="regla"></div>

        <div class="etiqueta">Remitente</div>
        <div>{{ $guia->sender_name }}</div>
        @if ($guia->sender_phone)
            <div>Tel. {{ $guia->sender_phone }}</div>
        @endif
        <div class="etiqueta" style="margin-top:1mm">Origen</div>
        <div>{{ $guia->pickupBranch?->prefixLabel() }} · {{ $guia->pickupBranch?->name }}</div>

        @if ($bulto?->weight || $bulto?->description)
            <div class="regla"></div>
            @if ($bulto->weight)
                <div><span class="etiqueta">Peso</span> {{ number_format((float) $bulto->weight, 2) }} kg</div>
            @endif
            @if ($bulto->description)
                <div><span class="etiqueta">Contenido</span> {{ $bulto->description }}</div>
            @endif
        @endif

        <div class="centro" style="margin-top:1.5mm; font-size:8px;">
            {{ $guia->created_at?->format('d/m/Y H:i') }}
        </div>
    </div>
@endforeach

<div class="no-imprimir centro" style="margin-top:6mm">
    <button type="button" onclick="window.print()"
            style="font:inherit;padding:6px 14px;border:1px solid #000;background:#fff;cursor:pointer">
        Imprimir de nuevo
    </button>
</div>

</body>
</html>
