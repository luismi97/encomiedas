<?php

namespace App\Support;

use App\Models\CompanySetting;
use App\Models\User;

/**
 * ¿Este usuario puede seguir creando guías sin conexión?
 *
 * Es la ÚNICA fuente de verdad, y la usan el controlador y el watchdog del
 * layout. Si divergieran, un usuario acabaría con las tarifas en el navegador
 * y redirigido a una pantalla que el servidor después le rechaza.
 *
 * Exige lo mismo que crear una guía en línea (rol de mostrador) más dos cosas:
 * que la empresa lo haya encendido y que el usuario tenga sede. Sin sede no
 * hay origen para la guía —en línea la elige, sin conexión no hay a quién
 * preguntarle cuál— ni caja donde registrar el cobro.
 */
final class ModoOffline
{
    public static function habilitado(?User $usuario = null): bool
    {
        $usuario ??= auth()->user();

        if (! $usuario || ! $usuario->company_id || ! $usuario->branch_id) {
            return false;
        }

        if (! $usuario->isAdmin() && ! $usuario->isCajero()) {
            return false;
        }

        try {
            return (bool) CompanySetting::instance()->offline_mode;
        } catch (\Throwable) {
            return false;
        }
    }
}
