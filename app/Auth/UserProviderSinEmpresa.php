<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;

/**
 * Busca usuarios sin filtrar por empresa.
 *
 * Autenticar es, por definición, lo que pasa ANTES de tener empresa: el correo
 * que se digita en el login puede ser de cualquiera de los clientes. Con el
 * ámbito global puesto, la búsqueda sale recortada a la empresa que hubiera en
 * contexto y el sistema responde «las credenciales no coinciden» a alguien que
 * escribió bien su contraseña —el peor mensaje de error posible, porque manda
 * a buscar el problema al lado equivocado—.
 *
 * Va en el proveedor y no en el controlador de login porque son cuatro caminos
 * distintos y todos tienen que quedar cubiertos: iniciar sesión, recordar la
 * sesión entre peticiones, la cookie de «recordarme» y el restablecimiento de
 * contraseña. Arreglar solo el primero deja los otros tres rompiéndose de a uno.
 *
 * Esto no afecta el aislamiento: lo que un usuario VE lo decide su propio
 * company_id, no cómo se lo encontró.
 */
class UserProviderSinEmpresa extends EloquentUserProvider
{
    protected function newModelQuery($model = null): Builder
    {
        return parent::newModelQuery($model)->withoutGlobalScopes();
    }
}
