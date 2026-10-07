<?php

namespace App\Services\Hacienda;

use App\Models\ElectronicInvoice;
use App\Models\Invoice;
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

        // La condición sale de la guía; config es solo el respaldo para
        // comprobantes viejos que no la tienen.
        $condicion = $this->electronicInvoice->invoice?->sale_condition
            ?: config('hacienda.sale_condition');
        $root->appendChild($this->el('CondicionVenta', $condicion));

        // Hacienda exige el plazo cuando la venta es a crédito.
        if ($condicion === Invoice::SALE_CREDIT) {
            $plazo = (int) ($this->electronicInvoice->invoice?->credit_term_days ?: 30);
            $root->appendChild($this->el('PlazoCredito', (string) $plazo));
        }
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
            $impuesto = $this->impuestoDeLinea($subTotal, $rate);

            // `price` es el total de la línea. Hacienda valida que
            // PrecioUnitario × Cantidad = MontoTotal, y el precio por bulto
            // viene digitado con dos decimales, así que la división es exacta.
            $cantidad = max(1, (int) ($item['cantidad'] ?? 1));

            $this->lines[] = [
                'numero'     => $line,
                'cabys'      => $item['cabys'] ?? $defaultCabys,
                'cantidad'   => $cantidad,
                'detalle'    => mb_substr($item['detalle'], 0, 160),
                'precio'     => round($gross / $cantidad, 5),
                'montoTotal' => $gross,
                'descuento'  => $lineDiscount,
                'subTotal'   => $subTotal,
                'iva_rate'   => $rate,
                'iva_codigo' => $codigoTarifa,
            ] + $impuesto + [
                'totalLinea' => round($subTotal + $impuesto['neto'], 5),
            ];
        }

        $this->resumirLineas();
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
            $detalle = 'Servicio de encomienda - ' . $item->nombreDelBulto();
            if ($item->description) {
                $detalle .= ' (' . $item->description . ')';
            }
            $out[] = [
                'price'    => (float) $item->price,
                'cantidad' => $item->cantidad(),
                'cabys'    => $item->cabys_code ?: $defaultCabys,
                'detalle' => $detalle,
            ];
        }

        /*
         | El seguro y el domicilio son parte de lo que se cobra, así que tienen
         | que ser líneas del comprobante. Sin ellas el total del XML no cuadra
         | con el de la guía y Hacienda lo rechaza por descuadre —o peor, se
         | acepta un comprobante por menos de lo que el cliente pagó—.
         */
        if (($seguro = (float) $invoice->insurance_fee) > 0) {
            $out[] = [
                'price'   => $seguro,
                'cabys'   => $defaultCabys,
                'detalle' => 'Seguro sobre valor declarado de ₡'
                    . number_format((float) $invoice->declared_value, 2),
            ];
        }

        if (($domicilio = (float) $invoice->home_delivery_fee) > 0) {
            $out[] = [
                'price'   => $domicilio,
                'cabys'   => $defaultCabys,
                'detalle' => 'Entrega a domicilio',
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
            // Lo cobrado: TotalImpuesto suma los netos, y Hacienda exige que el
            // desglose cuadre con él.
            $desglose[$codigo]['monto'] = round($desglose[$codigo]['monto'] + (float) ($l['neto'] ?? $l['iva']), 5);
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
            $linea->appendChild($this->el('UnidadMedida', $this->isService($l['cabys'] ?? null)
                ? config('hacienda.measurement_unit')
                : config('hacienda.measurement_unit_goods')));
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
            if (($l['exonerado'] ?? 0) > 0) {
                $imp->appendChild($this->buildExoneracion($l));
            }
            $linea->appendChild($imp);

            $linea->appendChild($this->el('ImpuestoAsumidoEmisorFabrica', '0.00'));
            $linea->appendChild($this->el('ImpuestoNeto', number_format($l['neto'] ?? $l['iva'], 2, '.', '')));
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
        if (($r['serv_exonerado'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalServExonerado', $this->num($r['serv_exonerado'])));
        }
        if (($r['merc_gravada'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalMercanciasGravadas', $this->num($r['merc_gravada'])));
        }
        if (($r['merc_exenta'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalMercanciasExentas', $this->num($r['merc_exenta'])));
        }
        if (($r['merc_exonerada'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalMercExonerada', $this->num($r['merc_exonerada'])));
        }
        if (($r['gravado'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalGravado', $this->num($r['gravado'])));
        }
        if (($r['exento'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalExento', $this->num($r['exento'])));
        }
        if (($r['exonerado'] ?? 0) > 0) {
            $resumen->appendChild($this->el('TotalExonerado', $this->num($r['exonerado'])));
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
        // El precio digitado es lo que pagó el cliente: con exoneración, sin
        // la parte del IVA que no se le cobró.
        $factor = 1 + $this->tarifaNeta($rate) / 100;

        $defaultCabys = $original->emisor_data['default_cabys']
            ?? $emisor['default_cabys']
            ?? config('hacienda.default_cabys_code');

        foreach (array_values($noteLines) as $i => $line) {
            $qty      = max(0.001, (float) ($line['cantidad'] ?? 1));
            $unitIncl = round(abs((float) ($line['precio'] ?? 0)), 5);
            $unitBase = $rate > 0 ? round($unitIncl / $factor, 5) : $unitIncl;
            $gross    = round($unitBase * $qty, 5);
            $impuesto = $this->impuestoDeLinea($gross, $rate);
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
                'iva_rate'   => $rate,
                'iva_codigo' => Catalogs::ivaRateCode($rate),
            ] + $impuesto + [
                'totalLinea' => round($gross + $impuesto['neto'], 5),
            ];
        }

        $this->resumirLineas();
    }

    // ---------------------------------------------------------------------
    // Impuesto y exoneración
    // ---------------------------------------------------------------------

    /**
     * La exoneración que declara el comprobante, o null.
     *
     * Viaja en receptor_data porque es del receptor: Hacienda la contrasta con
     * EXONET por la cédula de quien se factura. Las notas copian receptor_data
     * del original, así que la heredan sin hacer nada. Un tiquete no tiene
     * receptor y no puede declararla.
     *
     * @return array<string,mixed>|null
     */
    protected function exoneracion(): ?array
    {
        if (!$this->includesReceptor()) {
            return null;
        }

        $exo = $this->electronicInvoice->receptor_data['exoneracion'] ?? null;

        return is_array($exo) && filled($exo['numero'] ?? null) ? $exo : null;
    }

    /** Lo que efectivamente se cobra de IVA, en puntos porcentuales. */
    protected function tarifaNeta(float $rate): float
    {
        $exo = $this->exoneracion();

        return $exo ? max(0.0, $rate - (float) ($exo['tarifa'] ?? $rate)) : $rate;
    }

    /**
     * IVA de una línea: el de la tarifa (Monto), lo exonerado
     * (MontoExoneracion) y lo cobrado (ImpuestoNeto = Monto − exonerado).
     *
     * Con exoneración los tres van a dos decimales ANTES de restar: así salen
     * en el XML, y redondear después podía dejar un neto que no fuera
     * exactamente Monto − MontoExoneracion.
     *
     * @return array{iva:float, exonerado:float, neto:float, tarifa_exonerada:float}
     */
    protected function impuestoDeLinea(float $base, float $rate): array
    {
        $iva = $rate > 0 ? round($base * $rate / 100, 5) : 0.0;
        $exo = $this->exoneracion();

        if (!$exo || $iva <= 0) {
            return ['iva' => $iva, 'exonerado' => 0.0, 'neto' => $iva, 'tarifa_exonerada' => 0.0];
        }

        // MontoExoneracion = BaseImponible × TarifaExonerada / 100. No puede
        // exonerarse más de lo que la tarifa cobra.
        $tarifaExonerada = min($rate, max(0.0, (float) ($exo['tarifa'] ?? $rate)));
        $iva = round($iva, 2);
        $exonerado = $tarifaExonerada >= $rate ? $iva : round($base * $tarifaExonerada / 100, 2);

        return [
            'iva'              => $iva,
            'exonerado'        => $exonerado,
            'neto'             => round($iva - $exonerado, 2),
            'tarifa_exonerada' => $tarifaExonerada,
        ];
    }

    /**
     * Desglose y resumen a partir de las líneas.
     *
     * Hacienda clasifica cada línea como mercancía o servicio por el CABYS,
     * no por la unidad de medida. Meter todo en TotalServGravados cuando el
     * CABYS es de un bien produce el par de rechazos -111 ("el total de
     * servicios gravados no coincide con la suma de servicios gravados (0)")
     * y -110 ("el resumen carece del total de mercancias gravadas, pero
     * cuenta con mercancias gravadas").
     *
     * Una línea exonerada reparte su MontoTotal: la parte de la tarifa que se
     * exoneró va a «exonerado» y el resto sigue «gravado». Con la exoneración
     * completa (13 de 13) todo es exonerado. TotalVenta es la suma de gravado,
     * exento y exonerado, y TotalImpuesto la de los ImpuestoNeto.
     */
    protected function resumirLineas(): void
    {
        $this->desglosePorTarifa = $this->buildDesgloseFromLines();

        $t = array_fill_keys([
            'serv_gravado', 'serv_exento', 'serv_exonerado',
            'merc_gravada', 'merc_exenta', 'merc_exonerada',
        ], 0.0);

        foreach ($this->lines as $l) {
            $monto = (float) $l['montoTotal'];
            $esServicio = $this->isService($l['cabys'] ?? null);

            if (($l['iva'] ?? 0) <= 0) {
                $t[$esServicio ? 'serv_exento' : 'merc_exenta'] += $monto;
                continue;
            }

            $rate = (float) $l['iva_rate'];
            $proporcion = ($l['exonerado'] ?? 0) > 0 && $rate > 0
                ? min(1.0, (float) $l['tarifa_exonerada'] / $rate)
                : 0.0;
            $exonerado = round($monto * $proporcion, 5);

            $t[$esServicio ? 'serv_exonerado' : 'merc_exonerada'] += $exonerado;
            $t[$esServicio ? 'serv_gravado' : 'merc_gravada'] += $monto - $exonerado;
        }

        $t = array_map(fn ($v) => round($v, 5), $t);

        $totalVenta     = round(array_sum(array_column($this->lines, 'montoTotal')), 5);
        $totalDescuento = round(array_sum(array_column($this->lines, 'descuento')), 5);
        $totalVentaNeta = round($totalVenta - $totalDescuento, 5);
        $totalImpuesto  = round(array_sum(array_map(fn ($l) => (float) ($l['neto'] ?? $l['iva']), $this->lines)), 5);

        $this->resumen = $t + [
            'gravado'      => round($t['serv_gravado'] + $t['merc_gravada'], 5),
            'exento'       => round($t['serv_exento'] + $t['merc_exenta'], 5),
            'exonerado'    => round($t['serv_exonerado'] + $t['merc_exonerada'], 5),
            'iva_exonerado' => round(array_sum(array_column($this->lines, 'exonerado')), 5),
            'total_venta'  => $totalVenta,
            'descuentos'   => $totalDescuento,
            'venta_neta'   => $totalVentaNeta,
            'impuesto'     => $totalImpuesto,
            'otros_cargos' => 0.0,
            'total'        => round($totalVentaNeta + $totalImpuesto, 5),
        ];
    }

    /**
     * Nodo Exoneracion (ExoneracionType v4.4), dentro de Impuesto y después de
     * Monto. Los «Otros» (99) exigen describirse; el resto, no llevarlo.
     */
    protected function buildExoneracion(array $l): DOMElement
    {
        $exo = $this->exoneracion() ?? [];
        $nodo = $this->doc->createElement('Exoneracion');

        $tipo = (string) ($exo['tipo'] ?? '99');
        $nodo->appendChild($this->el('TipoDocumentoEX1', $tipo));
        if ($tipo === '99') {
            $nodo->appendChild($this->el('TipoDocumentoOTRO', mb_substr((string) ($exo['tipo_otro'] ?? ''), 0, 100)));
        }
        $nodo->appendChild($this->el('NumeroDocumento', mb_substr((string) $exo['numero'], 0, 40)));
        if (filled($exo['articulo'] ?? null)) {
            $nodo->appendChild($this->el('Articulo', (string) (int) $exo['articulo']));
        }
        if (filled($exo['inciso'] ?? null)) {
            $nodo->appendChild($this->el('Inciso', (string) (int) $exo['inciso']));
        }

        $institucion = (string) ($exo['institucion'] ?? '99');
        $nodo->appendChild($this->el('NombreInstitucion', $institucion));
        if ($institucion === '99') {
            $nodo->appendChild($this->el('NombreInstitucionOtros', mb_substr((string) ($exo['institucion_otro'] ?? ''), 0, 160)));
        }

        $fecha = Carbon::parse($exo['fecha_emision'] ?? $this->electronicInvoice->issued_at, config('app.timezone') ?: 'America/Costa_Rica');
        $nodo->appendChild($this->el('FechaEmisionEX', $fecha->format('Y-m-d\TH:i:sP')));
        $nodo->appendChild($this->el('TarifaExonerada', number_format((float) $l['tarifa_exonerada'], 2, '.', '')));
        $nodo->appendChild($this->el('MontoExoneracion', number_format((float) $l['exonerado'], 2, '.', '')));

        return $nodo;
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
