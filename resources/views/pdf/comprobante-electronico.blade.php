<html>
<head>
<meta charset="utf-8">
<style>
    /*
       Representación gráfica del comprobante electrónico, en el formato que
       los contadores ya conocen: emisor con QR arriba, receptor y documento
       lado a lado, detalle, desglose del IVA y totales.

       DomPDF no entiende flexbox ni grid: todo va con <table>. Los montos con
       punto de miles y coma decimal, como se leen en Costa Rica.
    */
    @page { margin: 34px 36px 50px 36px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #111; }
    table { border-collapse: collapse; }

    .encabezado { width: 100%; }
    .encabezado td { vertical-align: top; }
    .emisor-nombre { font-size: 11.5px; font-weight: bold; text-align: center; }
    .emisor-dato { font-size: 9.5px; }
    .emisor-dir { font-size: 8px; text-align: center; text-transform: uppercase; margin-top: 4px; }
    .linea-gruesa { border-top: 2px solid #111; margin: 8px 0 6px; }

    .bloques { width: 100%; }
    .bloques > tbody > tr > td { vertical-align: top; }
    .datos td { padding: 2px 4px; vertical-align: top; }
    .datos .rotulo { font-weight: bold; text-align: right; white-space: nowrap; width: 62px; font-size: 10px; }

    .barra { background: #111; color: #fff; font-weight: bold; text-align: center; padding: 3px; font-size: 10.5px; }
    .consecutivo { text-align: center; font-size: 10.5px; margin-top: 2px; }
    .clave-chica { text-align: center; font-size: 7px; word-wrap: break-word; }
    .doc td { text-align: center; padding: 1px 2px; font-size: 8.5px; }
    .doc .rotulo { font-weight: bold; font-size: 9.5px; padding-top: 4px; }

    .detalle { width: 100%; margin-top: 10px; }
    .detalle th { background: #111; color: #fff; font-size: 8.5px; padding: 3px 4px; text-align: left; border: 1px solid #111; }
    .detalle td { padding: 3px 4px; font-size: 8.5px; border: 1px solid #999; }
    .num, .detalle th.num { text-align: right; }

    .resumen-iva { width: 100%; }
    .resumen-iva th { background: #111; color: #fff; font-size: 8.5px; padding: 3px 4px; border: 1px solid #111; }
    .resumen-iva td { padding: 3px 4px; font-size: 8.5px; border: 1px solid #999; }

    .totales { width: 100%; }
    .totales td { padding: 2px 4px; font-size: 9.5px; }
    .totales .rotulo { text-align: right; }
    .totales .total td { font-size: 12px; font-weight: bold; padding-top: 5px; }

    .pie td { padding: 3px 0; vertical-align: top; }
    .pie .rotulo { font-weight: bold; width: 130px; font-size: 10px; }
    .leyenda { font-style: italic; font-size: 9px; margin-top: 6px; }
</style>
</head>
<body>
@php
    $m = fn ($v) => number_format((float) $v, 2, ',', '.');
    $c = fn ($v) => '₡' . number_format((float) $v, 2, ',', '.');
@endphp

    {{-- ── Emisor ───────────────────────────────────────────────────── --}}
    <table class="encabezado">
        <tr>
            <td style="width: 110px;">
                <img src="{{ $qr }}" style="width: 96px; height: 96px;" alt="QR">
            </td>
            <td>
                <div class="emisor-nombre">{{ $emisor['nombre'] }}</div>
                @if ($emisor['comercial'] && $emisor['comercial'] !== $emisor['nombre'])
                    <div class="emisor-nombre">{{ $emisor['comercial'] }}</div>
                @endif
                <table style="width: 100%; margin-top: 4px;">
                    <tr>
                        <td class="emisor-dato" style="width: 50%; text-align: center;">
                            <strong>Céd: {{ $emisor['cedula'] }}</strong>
                            @if ($emisor['telefono'])<br>Tel: {{ $emisor['telefono'] }}@endif
                        </td>
                        <td class="emisor-dato" style="width: 50%; text-align: center;">
                            @if ($emisor['email']){{ $emisor['email'] }}@endif
                        </td>
                    </tr>
                </table>
                @if ($emisor['direccion'])
                    <div class="emisor-dir">{{ $emisor['direccion'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="linea-gruesa"></div>

    {{-- ── Receptor y documento ─────────────────────────────────────── --}}
    <table class="bloques">
        <tr>
            <td style="width: 54%; padding-right: 10px;">
                <table class="datos" style="width: 100%;">
                    <tr>
                        <td class="rotulo">Cliente</td>
                        <td colspan="3">{{ $receptor['nombre'] ?? 'Consumidor final' }}</td>
                    </tr>
                    @if ($receptor)
                        <tr>
                            <td class="rotulo">Cédula</td>
                            <td>{{ $receptor['cedula'] }}</td>
                            <td class="rotulo">Act. Econ.</td>
                            <td>{{ $actividadReceptor }}</td>
                        </tr>
                        <tr><td class="rotulo">Email</td><td colspan="3" style="font-size: 8.5px;">{{ $receptor['email'] }}</td></tr>
                        <tr><td class="rotulo">Teléfono</td><td colspan="3">{{ $receptor['telefono'] }}</td></tr>
                        <tr><td class="rotulo">Dirección</td><td colspan="3" style="font-size: 8.5px; text-transform: uppercase;">{{ $receptor['direccion'] }}</td></tr>
                    @endif
                    <tr><td colspan="4" style="height: 6px;"></td></tr>
                    <tr>
                        <td class="rotulo">Pago</td>
                        <td colspan="3">{{ $pago }}</td>
                    </tr>
                </table>
            </td>
            <td style="width: 46%;">
                <div class="barra">{{ $titulo }}</div>
                <div class="consecutivo">{{ $consecutivo }}</div>
                <div class="clave-chica">{{ $clave }}</div>

                <table class="doc" style="width: 100%; margin-top: 3px;">
                    <tr>
                        <td class="rotulo" style="width: 33%;">Tipo</td>
                        <td class="rotulo" style="width: 34%;">Fecha</td>
                        <td class="rotulo" style="width: 33%;">Plazo</td>
                    </tr>
                    <tr>
                        <td>{{ $condicion }}</td>
                        <td>{{ $fecha->format('d/m/Y H:i') }}</td>
                        <td>{{ $plazo > 0 ? $plazo . ' días' : '—' }}</td>
                    </tr>
                    @if ($vence)
                        <tr>
                            <td class="rotulo">Vence</td>
                            <td>{{ $vence->format('d/m/Y') }}</td>
                            <td></td>
                        </tr>
                    @endif
                    <tr>
                        <td class="rotulo">Moneda</td>
                        <td class="rotulo">Tipo Cambio</td>
                        <td class="rotulo">Act. Econ.</td>
                    </tr>
                    <tr>
                        <td>{{ $moneda }}</td>
                        <td>{{ number_format($tipoCambio, 5, ',', '.') }}</td>
                        <td>{{ $emisor['actividad'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Detalle ──────────────────────────────────────────────────── --}}
    <table class="detalle">
        <thead>
            <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Unidad</th>
                <th class="num">Cantidad</th>
                <th class="num">Precio</th>
                <th class="num">Subtotal</th>
                <th class="num">Descuento</th>
                <th class="num">% IVA</th>
                <th class="num">Impuesto</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lineas as $l)
                <tr>
                    <td style="font-size: 7.5px;">{{ $l['codigo'] }}</td>
                    <td>{{ $l['detalle'] }}</td>
                    <td>{{ $l['unidad'] }}</td>
                    <td class="num">{{ $m($l['cantidad']) }}</td>
                    <td class="num">{{ $m($l['precio']) }}</td>
                    <td class="num">{{ $m($l['subtotal']) }}</td>
                    <td class="num">{{ $m($l['descuento']) }}</td>
                    <td class="num">{{ $m($l['tarifa']) }}</td>
                    <td class="num">{{ $m($l['impuesto']) }}</td>
                    <td class="num">{{ $m($l['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ── Desglose del IVA y totales ──────────────────────────────── --}}
    <table style="width: 100%; margin-top: 8px;">
        <tr>
            <td style="width: 64%; vertical-align: top; padding-right: 14px;">
                <table class="resumen-iva">
                    <thead>
                        <tr>
                            <th>% IVA</th>
                            <th class="num">Monto impuesto</th>
                            <th class="num">Monto exonerado</th>
                            <th class="num">Origen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($desgloseIva as $iva)
                            <tr>
                                <td>{{ $m($iva['tarifa']) }}</td>
                                <td class="num">{{ $m($iva['impuesto']) }}</td>
                                <td class="num">{{ $m($iva['exonerado']) }}</td>
                                <td class="num">Línea</td>
                            </tr>
                        @empty
                            <tr><td colspan="4">Sin impuesto</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($guia)
                    <div style="font-size: 8.5px; margin-top: 4px;">{{ $guia }}</div>
                @endif
            </td>
            <td style="width: 36%; vertical-align: top;">
                <table class="totales">
                    <tr><td class="rotulo">Subtotal</td><td class="num">{{ $c($totales['subtotal']) }}</td></tr>
                    <tr><td class="rotulo">- Descuento</td><td class="num">{{ $c($totales['descuento']) }}</td></tr>
                    <tr><td class="rotulo">+ Impuesto</td><td class="num">{{ $c($totales['impuesto']) }}</td></tr>
                    <tr><td class="rotulo">+ Cargo Consumo</td><td class="num">{{ $c($totales['otrosCargos']) }}</td></tr>
                    <tr><td class="rotulo">- IVA Devuelto</td><td class="num">{{ $c($totales['ivaDevuelto']) }}</td></tr>
                    <tr class="total"><td class="rotulo">TOTAL</td><td class="num">{{ $c($totales['total']) }}</td></tr>
                    <tr><td class="rotulo" style="padding-top: 6px;">Exonerado</td><td class="num" style="padding-top: 6px;">{{ $c($totales['exonerado']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Clave y referencias ─────────────────────────────────────── --}}
    <table class="pie" style="width: 100%; margin-top: 10px;">
        <tr>
            <td class="rotulo">Clave numérica</td>
            <td style="font-size: 9px;">{{ $clave }}</td>
        </tr>
        <tr>
            <td class="rotulo">Afecta Documento</td>
            <td style="font-size: 9px;">
                @if ($referencia)
                    {{ $referencia['tipo'] }} {{ $referencia['numero'] }}
                    @if ($referencia['fecha']) · {{ $referencia['fecha'] }} @endif
                @endif
            </td>
        </tr>
    </table>

    @if ($leyenda)
        <div class="leyenda">{{ $leyenda }}</div>
    @endif

    <table class="pie" style="width: 100%; margin-top: 8px;">
        <tr>
            <td class="rotulo">Notas</td>
            <td style="font-size: 9px;">{{ $referencia['razon'] ?? '' }}</td>
        </tr>
    </table>
</body>
</html>
