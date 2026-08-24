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

    /* Una línea por bulto. Tabla y no flex: DomPDF ignora flexbox y en algunos
       flujos esta vista también se renderiza a PDF. */
    .bultos { width: 100%; border-collapse: collapse; margin-top: 1.5mm; }
    .bultos td { padding: 0.6mm 0; vertical-align: top; border-bottom: 1px dotted #999; }
    .bultos tr:last-child td { border-bottom: none; }
    .bultos .n { width: 5mm; font-weight: bold; }
    .bultos .der { text-align: right; white-space: nowrap; }

    .domicilio {
        border: 2px solid #000;
        padding: 1mm;
        margin-top: 1.5mm;
        font-weight: bold;
        font-size: {{ $ancho >= 80 ? '14px' : '12px' }};
    }

    /* Invertido: en térmica el negro sólido es lo único que se ve de lejos. */
    .cobrar {
        background: #000;
        color: #fff;
        padding: 1.5mm;
        margin-top: 1.5mm;
        font-weight: bold;
        font-size: {{ $ancho >= 80 ? '16px' : '13px' }};
        letter-spacing: 1px;
    }

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

{{-- Un solo tiquete con los bultos como líneas.

     Antes se repetía la etiqueta entera por bulto —encabezado, destino, código
     de barras, remitente, todo— y una guía de cinco paquetes salía en metro y
     medio de papel. Lo que cambia entre bultos es una línea; el resto es igual.

     Con ?porBulto=1 se vuelve a una etiqueta por paquete, para cuando de verdad
     hace falta pegarle una a cada caja. --}}
@php $porBulto = request()->boolean('porBulto'); @endphp

@foreach ($porBulto ? $bultos : [null] as $indice => $soloEste)
    <div class="corte">
        <div class="centro etiqueta">{{ $empresa->commercial_name ?: $empresa->name }}</div>

        <div class="regla"></div>

        <div class="centro">
            <div class="etiqueta">Destino</div>
            <div class="ruta">{{ $guia->deliveryBranch?->prefix }}</div>
            <div class="destino-sede">{{ $guia->deliveryBranch?->name }}</div>
            @if ($guia->esADomicilio())
                <div class="domicilio">A DOMICILIO</div>
                <div style="font-size: {{ $ancho >= 80 ? '11px' : '10px' }}">{{ $guia->delivery_address }}</div>
            @endif
        </div>

        <div class="regla"></div>

        {{-- El código de barras es el motivo del tiquete: se escanea en
             recepción, en el despacho y en la entrega. --}}
        <div class="centro barras">{!! $barras !!}</div>
        <div class="centro codigo">{{ $guia->code }}</div>

        <div class="regla"></div>

        @if ($porBulto)
            <div class="centro bulto">
                BULTO {{ $indice + 1 }} DE {{ count($bultos) }}
                @if ($soloEste?->packageType)
                    <div style="font-size: {{ $ancho >= 80 ? '13px' : '11px' }}">
                        {{ mb_strtoupper($soloEste->packageType->name, 'UTF-8') }}
                    </div>
                @endif
            </div>
        @else
            <div class="centro bulto">
                {{ count($bultos) }} {{ count($bultos) === 1 ? 'BULTO' : 'BULTOS' }}
            </div>

            {{-- Una línea por paquete: es lo único que cambia entre ellos. --}}
            <table class="bultos">
                @foreach ($bultos as $i => $b)
                    <tr>
                        <td class="n">{{ $i + 1 }}</td>
                        <td>
                            {{ $b?->nombreDelBulto() ?? 'Bulto' }}
                            @if ($b?->description) · {{ $b->description }} @endif
                            @if ($b?->esFragil()) <strong>· FRÁGIL</strong> @endif
                        </td>
                        <td class="der">{{ $b?->weight ? number_format((float) $b->weight, 2) . ' kg' : '' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        {{-- Lo primero que tiene que ver quien entrega: si no cobra, la plata
             se pierde. --}}
        @if ($guia->tieneCobroPendiente())
            <div class="centro cobrar">
                POR COBRAR
                <div style="font-size: {{ $ancho >= 80 ? '20px' : '16px' }}">
                    ₡{{ number_format((float) $guia->total, 2) }}
                </div>
            </div>
        @endif

        @php
            $hayFragil = $porBulto ? $soloEste?->esFragil() : collect($bultos)->contains(fn ($b) => $b?->esFragil());
        @endphp

        @if ($hayFragil)
            <div class="centro frag">FRÁGIL · MANEJAR CON CUIDADO</div>
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

        @if ($porBulto && ($soloEste?->weight || $soloEste?->description))
            <div class="regla"></div>
            @if ($soloEste->weight)
                <div><span class="etiqueta">Peso</span> {{ number_format((float) $soloEste->weight, 2) }} kg</div>
            @endif
            @if ($soloEste->description)
                <div><span class="etiqueta">Contenido</span> {{ $soloEste->description }}</div>
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
