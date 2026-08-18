<?php

namespace App\Models\Concerns;

use App\Scopes\BranchScope;

/**
 * Marca un modelo como acotado por sede.
 *
 * Por defecto filtra por branch_id; un modelo con varias columnas de sede
 * —una guía tiene origen y destino— sobreescribe branchColumns().
 */
trait BelongsToBranch
{
    protected static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope());
    }

    /** @return array<int,string> */
    public function branchColumns(): array
    {
        return ['branch_id'];
    }
}
