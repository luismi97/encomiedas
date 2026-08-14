<?php

namespace App\Services\Hacienda;

class TiqueteElectronicoXml extends XmlBuilder
{
    protected function rootName(): string
    {
        return 'TiqueteElectronico';
    }

    protected function documentLetter(): string
    {
        return 'TE';
    }

    protected function includesReceptor(): bool
    {
        return false;
    }
}
