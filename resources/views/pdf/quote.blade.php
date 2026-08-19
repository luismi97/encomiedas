<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $cotizacion->code }}</title>
<style>
    /* Sin flexbox ni degradados: DomPDF los ignora y el texto sale sobre el
       fondo equivocado. Tablas y colores sólidos. */
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 0; padding: 26px 30px; }

    .encabezado { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
    .encabezado td { vertical-align: top; }
    .marca { font-size: 17px; font-weight: bold; color: #1e3a8a; }
    .sello { background-color: #1e3a8a; color: #fff; padding: 8px 12px; text-align: center; }
    .sello .n { font-size: 15px; font-weight: bold; letter-spacing: 1px; }

    .aviso { background-color: #fef3c7; border: 1px solid #f59e0b; padding: 7px 10px; margin-bottom: 14px; font-size: 10.5px; }

    .bloques { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .bloques td { vertical-align: top; width: 50%; padding-right: 14px; }
    .rotulo { text-transform: uppercase; font-size: 8.5px; letter-spacing: .5px; color: #6b7280; }

    table.detalle { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.detalle th { background-color: #1f2937; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
    table.detalle td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
    .der { text-align: right; }

    .totales { width: 46%; border-collapse: collapse; margin-left: 54%; margin-top: 12px; }
    .totales td { padding: 4px 8px; }
    .totales .final td { background-color: #1f2937; color: #fff; font-weight: bold; font-size: 13px; }

    .pie { margin-top: 22px; border-top: 1px solid #d1d5db; padding-top: 8px; font-size: 9.5px; color: #6b7280; }
</style>
</head>
<body>

<table class="encabezado">
    <tr>
        <td>
            <div class="marca">{{ $empresa->commercial_name ?: $empresa->name }}</div>
            @if ($empresa->identification_number)<div>Céd. {{ $empresa->identification_number }}</div>@endif
            @if ($empresa->phone)<div>Tel. {{ $empresa->phone }}</div>@endif
            @if ($empresa->email)<div>{{ $empresa->email }}</div>@endif
        </td>
        <td style="width: 200px;">
            <div class="sello">
                <div style="font-size:9px;letter-spacing:1px">COTIZACIÓN</div>
                <div class="n">{{ $cotizacion->code }}</div>
                <div style="font-size:9px">{{ $cotizacion->created_at->format('d/m/Y') }}</div>
            </div>
        </td>
    </tr>
</table>

{{-- Lo primero que tiene que quedar claro: esto no es una factura. --}}
<div class="aviso">
    <strong>Este documento es una cotización, no una factura.</strong>
    No tiene validez tributaria y no constituye un cobro.
    @if ($cotizacion->valid_until)
        El precio se sostiene hasta el <strong>{{ $cotizacion->valid_until->format('d/m/Y') }}</strong>.
    @endif
</div>

<table class="bloques">
    <tr>
        <td>
            <div class="rotulo">Cotizado a</div>
            <div style="font-size:12px;font-weight:bold">{{ $cotizacion->customer_name }}</div>
            @if ($cotizacion->customer_phone)<div>Tel. {{ $cotizacion->customer_phone }}</div>@endif
            @if ($cotizacion->customer_email)<div>{{ $cotizacion->customer_email }}</div>@endif
        </td>
        <td>
            <div class="rotulo">Ruta</div>
            <div style="font-size:12px;font-weight:bold">
                {{ $cotizacion->originBranch?->name ?? '—' }} &rarr; {{ $cotizacion->destinationBranch?->name ?? '—' }}
            </div>
            <div>{{ $cotizacion->rutaLabel() }}</div>
        </td>
    </tr>
</table>

<table class="detalle">
    <thead>
        <tr>
            <th>Bulto</th>
            <th>Descripción</th>
            <th class="der">Peso</th>
            <th class="der">Medidas (cm)</th>
            <th class="der">Precio</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($cotizacion->items as $item)
            <tr>
                <td>{{ $item->nombreDelBulto() }}</td>
                <td>{{ $item->description ?: '—' }}</td>
                <td class="der">{{ $item->weight ? number_format((float) $item->weight, 2) . ' kg' : '—' }}</td>
                <td class="der">
                    @if ($item->length_cm && $item->width_cm && $item->height_cm)
                        {{ rtrim(rtrim(number_format((float) $item->length_cm, 1), '0'), '.') }} ×
                        {{ rtrim(rtrim(number_format((float) $item->width_cm, 1), '0'), '.') }} ×
                        {{ rtrim(rtrim(number_format((float) $item->height_cm, 1), '0'), '.') }}
                    @else
                        —
                    @endif
                </td>
                <td class="der">&#8353;{{ number_format((float) $item->price, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totales">
    <tr>
        <td>Subtotal</td>
        <td class="der">&#8353;{{ number_format((float) $cotizacion->subtotal, 2) }}</td>
    </tr>
    @if ((float) $cotizacion->tax_total > 0)
        <tr>
            <td>Impuestos</td>
            <td class="der">&#8353;{{ number_format((float) $cotizacion->tax_total, 2) }}</td>
        </tr>
    @endif
    <tr class="final">
        <td>Total</td>
        <td class="der">&#8353;{{ number_format((float) $cotizacion->total, 2) }}</td>
    </tr>
</table>

@if ($cotizacion->notes)
    <div style="margin-top:16px">
        <div class="rotulo">Notas</div>
        <div>{{ $cotizacion->notes }}</div>
    </div>
@endif

<div class="pie">
    Cotización emitida por {{ $cotizacion->creator?->name ?? 'el sistema' }}
    el {{ $cotizacion->created_at->format('d/m/Y H:i') }}.
    Los precios pueden variar si cambian el peso, las medidas o la ruta declarados.
</div>

</body>
</html>
