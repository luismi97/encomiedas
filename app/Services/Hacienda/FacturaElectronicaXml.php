<?php

namespace App\Services\Hacienda;

class FacturaElectronicaXml extends XmlBuilder
{
    protected function rootName(): string
    {
        return 'FacturaElectronica';
    }

    protected function documentLetter(): string
    {
        return 'FE';
    }

    protected function includesReceptor(): bool
    {
        return true;
    }
}
