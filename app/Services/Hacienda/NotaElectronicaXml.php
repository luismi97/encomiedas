<?php

namespace App\Services\Hacienda;

/**
 * Base de las notas de crédito (03) y débito (02).
 *
 * IMPORTANTE: una nota lleva montos POSITIVOS. Lo que la convierte en un
 * reverso es el tipo de documento más el bloque InformacionReferencia, nunca
 * el signo: el tipo DecimalDineroType del esquema exige que todo monto sea
 * >= 0, así que un total negativo hace que Hacienda rechace el comprobante.
 */
abstract class NotaElectronicaXml extends FacturaElectronicaXml
{
    protected function includesReceptor(): bool
    {
        return true;
    }

    protected function includesRefDocumento(): bool
    {
        return true;
    }

    /** Etiqueta que encabeza el detalle de la línea ("Nota de Crédito", …). */
    abstract protected function docLabel(): string;

    /**
     * Si el usuario detalló líneas, se usan tal cual. Si no, la nota es por un
     * monto global y se arma una sola línea sintética con la tarifa de IVA del
     * comprobante original.
     */
    protected function computeTotals(): void
    {
        $noteLines = $this->electronicInvoice->note_lines;

        if (!empty($noteLines)) {
            $this->computeCustomNoteLines($noteLines, $this->docLabel());
            return;
        }

        $original = $this->electronicInvoice->referenceInvoice()->first();

        if (!$original) {
            parent::computeTotals();
            return;
        }

        $emisor = $this->electronicInvoice->emisor_data ?? [];
        $rate = (float) ($emisor['iva_rate'] ?? config('hacienda.tax.iva_rate'));
        $this->ivaPercent = $rate;

        // El monto de la nota viene con IVA incluido. Se despeja primero el
        // IVA y la base se saca por resta, para que base + IVA dé exactamente
        // el total y no quede un céntimo de diferencia por redondeo.
        $amount = round(abs((float) $this->electronicInvoice->total), 2);

        if ($rate > 0) {
            $iva  = round($amount - ($amount / (1 + $rate / 100)), 2);
            $base = round($amount - $iva, 2);
        } else {
            $base = $amount;
            $iva  = 0.0;
        }

        $cabys = $original->emisor_data['default_cabys']
            ?? $emisor['default_cabys']
            ?? config('hacienda.default_cabys_code');

        $this->lines[] = [
            'numero'     => 1,
            'cabys'      => $cabys,
            'cantidad'   => 1,
            'detalle'    => mb_substr($this->docLabel() . ': ' . ($this->electronicInvoice->reference_reason ?: 'Ajuste'), 0, 160),
            'precio'     => $base,
            'montoTotal' => $base,
            'descuento'  => 0,
            'subTotal'   => $base,
            'iva'        => $iva,
            'iva_rate'   => $rate,
            'iva_codigo' => Catalogs::ivaRateCode($rate),
            'totalLinea' => round($base + $iva, 5),
        ];

        $this->desglosePorTarifa = $this->buildDesgloseFromLines();

        $gravado   = $iva > 0;
        $isService = $this->isService($cabys);

        $this->resumen = [
            'serv_gravado' => ($gravado && $isService) ? $base : 0.0,
            'serv_exento'  => (!$gravado && $isService) ? $base : 0.0,
            'merc_gravada' => ($gravado && !$isService) ? $base : 0.0,
            'merc_exenta'  => (!$gravado && !$isService) ? $base : 0.0,
            'gravado'      => $gravado ? $base : 0.0,
            'exento'       => $gravado ? 0.0 : $base,
            'total_venta'  => $base,
            'descuentos'   => 0.0,
            'venta_neta'   => $base,
            'impuesto'     => $iva,
            'otros_cargos' => 0.0,
            'total'        => round($base + $iva, 5),
        ];
    }

    /**
     * Un solo medio de pago por el total (positivo). Hacienda solo valida que
     * la suma de los TotalMedioPago cuadre con TotalComprobante; el reverso lo
     * expresa el tipo de documento, no el medio ni el signo.
     */
    protected function mediosPago(): array
    {
        return [[
            'tipo'  => Catalogs::paymentMethod('cash'),
            'total' => round((float) ($this->resumen['total'] ?? 0), 5),
        ]];
    }
}
