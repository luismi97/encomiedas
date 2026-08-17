<?php

namespace App\Services\Hacienda;

/**
 * Nota de Débito (tipo 02): ajuste hacia arriba sobre un comprobante ya
 * aceptado (un cobro que faltó, un recargo posterior).
 */
class NotaDebitoXml extends NotaElectronicaXml
{
    protected function rootName(): string
    {
        return 'NotaDebitoElectronica';
    }

    protected function documentLetter(): string
    {
        return 'ND';
    }

    protected function docLabel(): string
    {
        return 'Nota de Débito';
    }
}
