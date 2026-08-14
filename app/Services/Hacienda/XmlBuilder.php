<?php

namespace App\Services\Hacienda;

use App\Models\ElectronicInvoice;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;

/**
 * Construye el XML (sin firmar) v4.4 de un comprobante electrónico de Costa
 * Rica a partir de un ElectronicInvoice + su Invoice (factura de encomienda).
 * Cada línea de detalle es un paquete de la factura (servicio de transporte).
 */
abstract class XmlBuilder
{
    protected DOMDocument $doc;
    protected ElectronicInvoice $electronicInvoice;

    /** @var array<int,array<string,mixed>> */
    protected array $lines = [];

    /** @var array<string,float> */
    protected array $resumen = [];

    /** @var array<string,array{tarifa:float,monto:float}> */
    protected array $desglosePorTarifa = [];

    protected float $ivaPercent = 0.0;

    abstract protected function rootName(): string;
    abstract protected function documentLetter(): string;
    abstract protected function includesReceptor(): bool;

    public function __construct(ElectronicInvoice $electronicInvoice)
    {
        $this->electronicInvoice = $electronicInvoice;
    }

    public function totals(): array
    {
        return $this->resumen;
    }

    public function build(): string
    {
        $this->computeTotals();

        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = false;

        $ns   = Catalogs::namespace($this->documentLetter());
        $root = $this->doc->createElement($this->rootName());
        $root->setAttribute('xmlns', $ns);
        $this->doc->appendChild($root);

        $emisor   = $this->electronicInvoice->emisor_data ?? [];
        $issuedAt = Carbon::parse($this->electronicInvoice->issued_at);

        $root->appendChild($this->el('Clave', $this->electronicInvoice->clave));
        $root->appendChild($this->el('ProveedorSistemas', $emisor['identification_number'] ?? ''));
        $root->appendChild($this->el('CodigoActividadEmisor', Catalogs::normalizeActivityCode($emisor['activity_code'] ?? null)));

        $receptorData = $this->electronicInvoice->receptor_data ?? [];
        $receptorActivity = Catalogs::normalizeActivityCode($receptorData['activity_code'] ?? null);
        if ($this->includesReceptor() && !empty($receptorData['numero']) && Catalogs::validActivityCode($receptorActivity)) {
            $root->appendChild($this->el('CodigoActividadReceptor', $receptorActivity));
        }

        $root->appendChild($this->el('NumeroConsecutivo', $this->electronicInvoice->consecutivo));
        $root->appendChild($this->el('FechaEmision', $issuedAt->format('Y-m-d\TH:i:sP')));

        $root->appendChild($this->buildEmisor($emisor));

        if ($this->includesReceptor() && !empty($receptorData['numero'])) {
            $root->appendChild($this->buildReceptor($receptorData));
        }

        $root->appendChild($this->el('CondicionVenta', config('hacienda.sale_condition')));
        $root->appendChild($this->buildDetalleServicio());
        $root->appendChild($this->buildResumen());

        return $this->doc->saveXML();
    }

    // ---------------------------------------------------------------------
    // Totales
    // ---------------------------------------------------------------------

    protected function computeTotals(): void
    {
        $invoice = $this->electronicInvoice->invoice;
        $emisor  = $this->electronicInvoice->emisor_data ?? [];
        $defaultCabys = $emisor['default_cabys'] ?? config('hacienda.default_cabys_code');

        // La tarifa de IVA del comprobante es la suma de los impuestos
        // configurados aplicados a la factura (normalmente uno solo: IVA).
        $this->ivaPercent = (float) $invoice->taxes->sum('percent');
        $rate = $this->ivaPercent;
        $factor = 1 + $rate / 100;
        $codigoTarifa = Catalogs::ivaRateCode($rate);

        $items = $this->sourceLineItems($defaultCabys);

        $sumGross = 0.0;
        foreach ($items as $item) {
            $sumGross += round((float) $item['price'], 5);
        }
        $discount = (float) ($invoice->discount_amount ?? 0);

        $line = 0;
        $discountApplied = 0.0;
        $count = count($items);
        foreach ($items as $item) {
            $line++;
            $gross = round((float) $item['price'], 5);

            $lineDiscount = 0.0;
            if ($discount > 0 && $sumGross > 0) {
                $lineDiscount = ($line === $count)
                    ? round($discount - $discountApplied, 5)
                    : round($discount * ($gross / $sumGross), 5);
                $discountApplied += $lineDiscount;
            }

            $subTotal = round($gross - $lineDiscount, 5);
            $iva = $rate > 0 ? round($subTotal * $rate / 100, 5) : 0.0;

            $this->lines[] = [
                'numero'     => $line,
                'cabys'      => $item['cabys'] ?? $defaultCabys,
                'cantidad'   => 1,
                'detalle'    => mb_substr($item['detalle'], 0, 160),
                'precio'     => $gross,
                'montoTotal' => $gross,
                'descuento'  => $lineDiscount,
                'subTotal'   => $subTotal,
                'iva'        => $iva,
                'iva_rate'   => $rate,
                'iva_codigo' => $codigoTarifa,
                'totalLinea' => round($subTotal + $iva, 5),
            ];
        }

        $this->desglosePorTarifa = $this->buildDesgloseFromLines();

        $gravado = $rate > 0 ? round(array_sum(array_column($this->lines, 'montoTotal')), 5) : 0.0;
        $exento  = $rate > 0 ? 0.0 : round(array_sum(array_column($this->lines, 'montoTotal')), 5);

        $totalVenta     = round(array_sum(array_column($this->lines, 'montoTotal')), 5);
        $totalDescuento = round(array_sum(array_column($this->lines, 'descuento')), 5);
        $totalVentaNeta = round($totalVenta - $totalDescuento, 5);
        $totalImpuesto  = round(array_sum(array_column($this->lines, 'iva')), 5);

        $this->resumen = [
            'serv_gravado' => $gravado,
            'serv_exento'  => $exento,
            'merc_gravada' => 0.0,
            'merc_exenta'  => 0.0,
            'gravado'      => $gravado,
            'exento'       => $exento,
            'total_venta'  => $totalVenta,
            'descuentos'   => $totalDescuento,
            'venta_neta'   => $totalVentaNeta,
            'impuesto'     => $totalImpuesto,
            'otros_cargos' => 0.0,
            'total'        => round($totalVentaNeta + $totalImpuesto, 5),
        ];
    }

    /**
     * Una línea por cada paquete de la factura (servicio de transporte).
     * @return array<int,array<string,mixed>>
     */
    protected function sourceLineItems($defaultCabys): array
    {
        $invoice = $this->electronicInvoice->invoice;
        $out = [];

        foreach ($invoice->items as $item) {
            $detalle = 'Servicio de encomienda - Paquete ' . $item->package_code;
            if ($item->description) {
                $detalle .= ' (' . $item->description . ')';
            }
            $out[] = [
                'price'   => (float) $item->price,
                'cabys'   => $item->cabys_code ?: $defaultCabys,
                'detalle' => $detalle,
            ];
        }

        return $out;
    }

    /** @return array<string,array{tarifa:float,monto:float}> */
    protected function buildDesgloseFromLines(): array
    {
        $desglose = [];
        foreach ($this->lines as $l) {
            $codigo = $l['iva_codigo'];
            if (!isset($desglose[$codigo])) {
                $desglose[$codigo] = ['tarifa' => (float) $l['iva_rate'], 'monto' => 0.0];
            }
            $desglose[$codigo]['monto'] = round($desglose[$codigo]['monto'] + (float) $l['iva'], 5);
        }

        return $desglose;
    }

    // ---------------------------------------------------------------------
    // Bloques
    // ---------------------------------------------------------------------

    protected function buildEmisor(array $e): DOMElement
    {
        $emisor = $this->doc->createElement('Emisor');
        $emisor->appendChild($this->el('Nombre', mb_substr($e['name'] ?? '', 0, 100)));

        $ident = $this->doc->createElement('Identificacion');
        $ident->appendChild($this->el('Tipo', $e['identification_type'] ?? ''));
        $ident->appendChild($this->el('Numero', $e['identification_number'] ?? ''));
        $emisor->appendChild($ident);

        if (!empty($e['commercial_name'])) {
            $emisor->appendChild($this->el('NombreComercial', mb_substr($e['commercial_name'], 0, 80)));
        }

        $ubic = $this->doc->createElement('Ubicacion');
        $ubic->appendChild($this->el('Provincia', $e['province'] ?? ''));
        $ubic->appendChild($this->el('Canton', str_pad((string) ($e['canton'] ?? ''), 2, '0', STR_PAD_LEFT)));
        $ubic->appendChild($this->el('Distrito', str_pad((string) ($e['district'] ?? ''), 2, '0', STR_PAD_LEFT)));
        if (!empty($e['barrio'])) {
            $ubic->appendChild($this->el('Barrio', $e['barrio']));
        }
        $ubic->appendChild($this->el('OtrasSenas', mb_substr($e['others_signs'] ?? 'San José', 0, 250)));
        $emisor->appendChild($ubic);

        if (!empty($e['phone'])) {
            $tel = $this->doc->createElement('Telefono');
            $tel->appendChild($this->el('CodigoPais', $e['phone_code'] ?? '506'));
            $tel->appendChild($this->el('NumTelefono', preg_replace('/\D/', '', $e['phone'])));
            $emisor->appendChild($tel);
        }

        $emisor->appendChild($this->el('CorreoElectronico', $e['email'] ?? ''));

        return $emisor;
    }

    protected function buildReceptor(array $r): DOMElement
    {
        $receptor = $this->doc->createElement('Receptor');
        if (!empty($r['nombre'])) {
            $receptor->appendChild($this->el('Nombre', mb_substr($r['nombre'], 0, 100)));
        }
        $ident = $this->doc->createElement('Identificacion');
        $ident->appendChild($this->el('Tipo', $r['tipo'] ?? '01'));
        $ident->appendChild($this->el('Numero', preg_replace('/\D/', '', $r['numero'])));
        $receptor->appendChild($ident);

        if (!empty($r['email'])) {
            $receptor->appendChild($this->el('CorreoElectronico', $r['email']));
        }

        return $receptor;
    }

    protected function buildDetalleServicio(): DOMElement
    {
        $detalle = $this->doc->createElement('DetalleServicio');

        foreach ($this->lines as $l) {
            $linea = $this->doc->createElement('LineaDetalle');
            $linea->appendChild($this->el('NumeroLinea', $l['numero']));
            $linea->appendChild($this->el('CodigoCABYS', $l['cabys']));
            $linea->appendChild($this->el('Cantidad', $this->num($l['cantidad'], 3)));
            $linea->appendChild($this->el('UnidadMedida', config('hacienda.measurement_unit')));
            $linea->appendChild($this->el('Detalle', $l['detalle']));
            $linea->appendChild($this->el('PrecioUnitario', $this->num($l['precio'])));
            $linea->appendChild($this->el('MontoTotal', $this->num($l['montoTotal'])));

            if ($l['descuento'] > 0) {
                $desc = $this->doc->createElement('Descuento');
                $desc->appendChild($this->el('MontoDescuento', $this->num($l['descuento'])));
                $desc->appendChild($this->el('NaturalezaDescuento', 'Descuento'));
                $linea->appendChild($desc);
            }

            $linea->appendChild($this->el('SubTotal', $this->num($l['subTotal'])));
            $linea->appendChild($this->el('BaseImponible', $this->num($l['subTotal'])));

            $imp = $this->doc->createElement('Impuesto');
            $imp->appendChild($this->el('Codigo', config('hacienda.tax.iva_code')));
            $imp->appendChild($this->el('CodigoTarifaIVA', $l['iva_codigo']));
            $imp->appendChild($this->el('Tarifa', number_format($l['iva_rate'], 2, '.', '')));
            $imp->appendChild($this->el('Monto', number_format($l['iva'], 2, '.', '')));
            $linea->appendChild($imp);

            $linea->appendChild($this->el('ImpuestoAsumidoEmisorFabrica', '0.00'));
            $linea->appendChild($this->el('ImpuestoNeto', number_format($l['iva'], 2, '.', '')));
            $linea->appendChild($this->el('MontoTotalLinea', $this->num($l['totalLinea'])));

            $detalle->appendChild($linea);
        }

        return $detalle;
    }

    protected function buildResumen(): DOMElement
    {
        $r = $this->resumen;
        $resumen = $this->doc->createElement('ResumenFactura');

        $moneda = $this->doc->createElement('CodigoTipoMoneda');
        $moneda->appendChild($this->el('CodigoMoneda', $this->electronicInvoice->currency_code ?: 'CRC'));
        $moneda->appendChild($this->el('TipoCambio', $this->num($this->electronicInvoice->exchange_rate ?: 1, 5)));
        $resumen->appendChild($moneda);

        if (($r['serv_gravado'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalServGravados', $this->num($r['serv_gravado'])));
        }
        if (($r['serv_exento'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalServExentos', $this->num($r['serv_exento'])));
        }
        if (($r['gravado'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalGravado', $this->num($r['gravado'])));
        }
        if (($r['exento'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalExento', $this->num($r['exento'])));
        }
        $resumen->appendChild($this->el('TotalVenta', $this->num($r['total_venta'] ?? 0)));
        if (($r['descuentos'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalDescuentos', $this->num($r['descuentos'])));
        }
        $resumen->appendChild($this->el('TotalVentaNeta', $this->num($r['venta_neta'] ?? 0)));

        foreach ($this->desglosePorTarifa as $codigo => $info) {
            $desglose = $this->doc->createElement('TotalDesgloseImpuesto');
            $desglose->appendChild($this->el('Codigo', config('hacienda.tax.iva_code')));
            $desglose->appendChild($this->el('CodigoTarifaIVA', $codigo));
            $desglose->appendChild($this->el('TotalMontoImpuesto', number_format($info['monto'], 2, '.', '')));
            $resumen->appendChild($desglose);
        }

        $resumen->appendChild($this->el('TotalImpuesto', $this->num($r['impuesto'] ?? 0)));

        // Sin registro de pagos parciales en este sistema: se declara todo en
        // efectivo salvo que se indique otro medio al momento del envío.
        $medio = $this->doc->createElement('MedioPago');
        $medio->appendChild($this->el('TipoMedioPago', Catalogs::paymentMethod('cash')));
        $medio->appendChild($this->el('TotalMedioPago', $this->num($r['total'] ?? 0)));
        $resumen->appendChild($medio);

        $resumen->appendChild($this->el('TotalComprobante', $this->num($r['total'] ?? 0)));

        return $resumen;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function el(string $name, $value = null): DOMElement
    {
        $node = $this->doc->createElement($name);
        if ($value !== null && $value !== '') {
            $node->appendChild($this->doc->createTextNode((string) $value));
        }
        return $node;
    }

    protected function num($value, int $decimals = 5): string
    {
        $formatted = number_format((float) $value, $decimals, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }
        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }
}
