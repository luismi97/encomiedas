<?php

namespace App\Scopes;

use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Acota toda consulta a la empresa que está operando.
 *
 * Va en la consulta y no en cada pantalla porque es donde no se puede olvidar:
 * una pantalla nueva que consulte guías queda aislada sin que su autor tenga
 * que acordarse, y ese olvido —en un sistema donde cada empresa ve datos
 * fiscales de sus clientes— es una fuga, no un bug de presentación.
 *
 * Cuando no hay empresa en contexto no filtra nada. Eso cubre tres casos
 * legítimos: el superadministrador, el worker de la cola y los comandos
 * programados, que recorren todas las empresas a propósito.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = CompanyContext::id();

        if ($companyId === null) {
            return;
        }

        $builder->where($model->getTable() . '.company_id', $companyId);
    }
}
