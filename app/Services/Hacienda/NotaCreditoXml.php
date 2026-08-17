<?php

namespace App\Services\Hacienda;

/**
 * Nota de Crédito (tipo 03): anula o rebaja una factura o tiquete ya aceptado.
 * Es la única forma de revertir un comprobante ante Hacienda — un comprobante
 * aceptado no se borra ni se edita.
 */
class NotaCreditoXml extends NotaElectronicaXml
{
    protected function rootName(): string
    {
        return 'NotaCreditoElectronica';
    }

    protected function documentLetter(): string
    {
        return 'NC';
    }

    protected function docLabel(): string
    {
        return 'Nota de Crédito';
    }
}
