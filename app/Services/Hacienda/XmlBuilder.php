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

    /** Solo las notas de crédito/débito llevan el bloque InformacionReferencia. */
    protected function includesRefDocumento(): bool
    {
        return false;
    }

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
        $root->appendChild($this->el('ProveedorSistemas', $emisor['proveedor_sistemas'] ?? $emisor['identification_number'] ?? ''));
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

        // El esquema v4.4 ubica InformacionReferencia DESPUÉS de ResumenFactura.
        if ($this->includesRefDocumento() && $this->electronicInvoice->reference_invoice_id) {
            $root->appendChild($this->buildInformacionReferencia());
        }

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
        if (($r['merc_gravada'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalMercanciasGravadas', $this->num($r['merc_gravada'])));
        }
        if (($r['merc_exenta'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalMercanciasExentas', $this->num($r['merc_exenta'])));
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

        foreach ($this->mediosPago() as $mp) {
            $medio = $this->doc->createElement('MedioPago');
            $medio->appendChild($this->el('TipoMedioPago', $mp['tipo']));
            $medio->appendChild($this->el('TotalMedioPago', $this->num($mp['total'])));
            $resumen->appendChild($medio);
        }

        $resumen->appendChild($this->el('TotalComprobante', $this->num($r['total'] ?? 0)));

        return $resumen;
    }

    /**
     * Medios de pago declarados. La suma de TotalMedioPago tiene que dar igual
     * que TotalComprobante, así que cualquier diferencia de redondeo se ajusta
     * contra el medio mayor.
     *
     * @return array<int,array{tipo:string,total:float}>
     */
    protected function mediosPago(): array
    {
        $total = round((float) ($this->resumen['total'] ?? 0), 5);
        $method = $this->electronicInvoice->invoice->payment_method ?? 'cash';

        return [[
            'tipo'  => Catalogs::paymentMethod($method),
            'total' => $total,
        ]];
    }

    /** Las secciones CABYS 6-9 son servicios; 0-5 son mercancías. */
    protected function isService(?string $cabys): bool
    {
        return $cabys !== null && $cabys !== '' && $cabys[0] >= '6';
    }

    /**
     * Bloque que amarra la nota al comprobante que corrige o anula.
     *
     * v4.4 renombró los campos de v4.3 con el sufijo "IR": TipoDocIR y
     * FechaEmisionIR. El Numero es la CLAVE de 50 dígitos del original, no su
     * consecutivo.
     */
    protected function buildInformacionReferencia(): DOMElement
    {
        $original = $this->electronicInvoice->referenceInvoice()->first();

        if (!$original) {
            throw new \RuntimeException('La nota no tiene comprobante de referencia.');
        }

        $info = $this->doc->createElement('InformacionReferencia');
        $info->appendChild($this->el('TipoDocIR', $original->document_type));
        $info->appendChild($this->el('Numero', $original->clave));
        $info->appendChild($this->el('FechaEmisionIR', Carbon::parse($original->issued_at)->format('Y-m-d\TH:i:sP')));
        $info->appendChild($this->el('Codigo', $this->reasonCode()));
        $info->appendChild($this->el('Razon', mb_substr($this->electronicInvoice->reference_reason ?: 'Anulación', 0, 180)));

        return $info;
    }

    /**
     * Código del catálogo de referencias v4.4. Se restringe a los valores que
     * no obligan a mandar el elemento "Otro": 01 anula, 02 corrige el monto.
     */
    protected function reasonCode(): string
    {
        $reason = mb_strtolower($this->electronicInvoice->reference_reason ?: '');

        foreach (['corrige', 'monto', 'descuento', 'ajuste'] as $needle) {
            if (str_contains($reason, $needle)) {
                return '02';
            }
        }

        return '01';
    }

    /**
     * Arma las líneas de una nota a partir de las filas que digitó el usuario.
     * Cada fila trae {detalle, cabys, cantidad, precio}, donde precio es el
     * precio con IVA incluido (el que ve el cliente); el IVA se despeja hacia
     * atrás con la tarifa del comprobante original.
     *
     * @param array<int,array<string,mixed>> $noteLines
     */
    protected function computeCustomNoteLines(array $noteLines, string $docLabel = 'Nota'): void
    {
        $original = $this->electronicInvoice->referenceInvoice()->first();
        $emisor   = $this->electronicInvoice->emisor_data ?? [];

        $rate = (float) ($emisor['iva_rate'] ?? config('hacienda.tax.iva_rate'));
        $this->ivaPercent = $rate;
        $factor = 1 + $rate / 100;

        $defaultCabys = $original->emisor_data['default_cabys']
            ?? $emisor['default_cabys']
            ?? config('hacienda.default_cabys_code');

        foreach (array_values($noteLines) as $i => $line) {
            $qty      = max(0.001, (float) ($line['cantidad'] ?? 1));
            $unitIncl = round(abs((float) ($line['precio'] ?? 0)), 5);
            $unitBase = $rate > 0 ? round($unitIncl / $factor, 5) : $unitIncl;
            $gross    = round($unitBase * $qty, 5);
            $iva      = $rate > 0 ? round($gross * $rate / 100, 5) : 0.0;
            $cabys    = !empty($line['cabys']) ? $line['cabys'] : $defaultCabys;
            $detalle  = !empty($line['detalle']) ? $line['detalle'] : ($docLabel . ' - Línea ' . ($i + 1));

            $this->lines[] = [
                'numero'     => $i + 1,
                'cabys'      => $cabys,
                'cantidad'   => $qty,
                'detalle'    => mb_substr($detalle, 0, 160),
                'precio'     => $unitBase,
                'montoTotal' => $gross,
                'descuento'  => 0,
                'subTotal'   => $gross,
                'iva'        => $iva,
                'iva_rate'   => $rate,
                'iva_codigo' => Catalogs::ivaRateCode($rate),
                'totalLinea' => round($gross + $iva, 5),
            ];
        }

        $this->desglosePorTarifa = $this->buildDesgloseFromLines();

        $servGravado = $servExento = $mercGravada = $mercExenta = 0.0;
        $totalBase = $totalIva = 0.0;

        foreach ($this->lines as $l) {
            $isService = $this->isService($l['cabys']);
            $gravado   = $l['iva'] > 0;

            if ($gravado && $isService) {
                $servGravado += $l['montoTotal'];
            } elseif ($gravado) {
                $mercGravada += $l['montoTotal'];
            } elseif ($isService) {
                $servExento += $l['montoTotal'];
            } else {
                $mercExenta += $l['montoTotal'];
            }

            $totalBase += $l['subTotal'];
            $totalIva  += $l['iva'];
        }

        $totalBase = round($totalBase, 5);
        $totalIva  = round($totalIva, 5);

        $this->resumen = [
            'serv_gravado' => round($servGravado, 5),
            'serv_exento'  => round($servExento, 5),
            'merc_gravada' => round($mercGravada, 5),
            'merc_exenta'  => round($mercExenta, 5),
            'gravado'      => round($servGravado + $mercGravada, 5),
            'exento'       => round($servExento + $mercExenta, 5),
            'total_venta'  => $totalBase,
            'descuentos'   => 0.0,
            'venta_neta'   => $totalBase,
            'impuesto'     => $totalIva,
            'otros_cargos' => 0.0,
            'total'        => round($totalBase + $totalIva, 5),
        ];
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
