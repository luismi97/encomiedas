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

        // El monto de la nota viene con IVA incluido —el que se cobró: con
        // exoneración, sin la parte exonerada—. Se despeja primero el IVA y
        // la base se saca por resta, para que base + IVA dé exactamente el
        // total y no quede un céntimo de diferencia por redondeo.
        $amount = round(abs((float) $this->electronicInvoice->total), 2);
        $neta = $this->tarifaNeta($rate);

        $base = $neta > 0
            ? round($amount - round($amount - ($amount / (1 + $neta / 100)), 2), 2)
            : $amount;

        $cabys = $original->emisor_data['default_cabys']
            ?? $emisor['default_cabys']
            ?? config('hacienda.default_cabys_code');

        $impuesto = $this->impuestoDeLinea($base, $rate);

        // Sin exoneración, el IVA despejado manda: recalcularlo sobre la base
        // podía correrse un céntimo y la nota dejaría de sumar su monto.
        if ($impuesto['exonerado'] <= 0) {
            $iva = round($amount - $base, 2);
            $impuesto = ['iva' => $iva, 'exonerado' => 0.0, 'neto' => $iva, 'tarifa_exonerada' => 0.0];
        }

        $this->lines[] = [
            'numero'     => 1,
            'cabys'      => $cabys,
            'cantidad'   => 1,
            'detalle'    => mb_substr($this->docLabel() . ': ' . ($this->electronicInvoice->reference_reason ?: 'Ajuste'), 0, 160),
            'precio'     => $base,
            'montoTotal' => $base,
            'descuento'  => 0,
            'subTotal'   => $base,
            'iva_rate'   => $rate,
            'iva_codigo' => Catalogs::ivaRateCode($rate),
        ] + $impuesto + [
            'totalLinea' => round($base + $impuesto['neto'], 5),
        ];

        $this->resumirLineas();
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
