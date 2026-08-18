<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Acota las consultas a la sede del usuario cuando su rol así lo exige.
 *
 * Bloquear rutas no alcanza: un cajero entra legítimamente al listado de guías
 * y a la caja, y ahí adentro vería lo de todas las sedes. Esto lo resuelve una
 * vez en la consulta, en vez de en cada pantalla —que es donde se cuelan las
 * fugas cuando alguien agrega una nueva y se olvida del filtro—.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // En cola y en consola no hay usuario: el worker de Hacienda y los cron
        // tienen que ver todo, o dejarían de procesar lo de otras sedes.
        if (! auth()->hasUser()) {
            return;
        }

        $user = auth()->user();

        if (! $user->limitadoASuSede() || ! $user->branch_id) {
            return;
        }

        $columnas = method_exists($model, 'branchColumns') ? $model->branchColumns() : ['branch_id'];
        $tabla = $model->getTable();

        // Agrupado: sin el paréntesis, un orWhere se escaparía de cualquier
        // otro filtro que la consulta traiga.
        $builder->where(function (Builder $query) use ($columnas, $tabla, $user) {
            foreach ($columnas as $columna) {
                $query->orWhere($tabla . '.' . $columna, $user->branch_id);
            }
        });
    }
}
