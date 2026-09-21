<?php

namespace App\Rules;

use App\Support\CompanyContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * «Ese id existe Y es de mi empresa».
 *
 * Hace falta porque `exists:branches,id` no pasa por Eloquent: consulta la
 * tabla en crudo y por lo tanto se salta el ámbito global. El agujero es real y
 * silencioso —el id viaja en un campo del formulario, y basta cambiarlo para
 * que un administrador cree una guía hacia la sede de otra empresa, o le asigne
 * un chofer ajeno—: la pantalla nunca ofreció esa opción, pero la validación la
 * aceptaba.
 *
 *     'pickup_branch_id' => ['required', DeLaEmpresa::en('branches')],
 *
 * Sin empresa en contexto la comparación queda contra NULL y no valida nada,
 * que es el lado correcto para fallar.
 */
final class DeLaEmpresa
{
    public static function en(string $tabla, string $columna = 'id'): Exists
    {
        return Rule::exists($tabla, $columna)->where('company_id', CompanyContext::id());
    }
}
