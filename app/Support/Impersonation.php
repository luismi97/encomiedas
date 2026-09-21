<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * El superadministrador entrando a una empresa como su administrador.
 *
 * Hace falta porque el superadministrador no pertenece a ninguna empresa: no
 * tiene sede, ni caja, ni configuración fiscal, así que las pantallas de
 * operación no sabrían qué mostrarle. En vez de inventar un modo «ver como»
 * que cada pantalla tendría que soportar, se inicia sesión de verdad como el
 * administrador de esa empresa —mismo camino, mismos permisos, mismos datos—.
 *
 * El id original queda en sesión para poder volver. Va en sesión y no en un
 * parámetro de URL a propósito: quien lo lleve vuelve a ser superadministrador,
 * y eso no puede depender de algo que el navegador pueda escribir.
 */
final class Impersonation
{
    private const CLAVE = 'suplantacion_origen';

    public static function empezar(User $destino): void
    {
        $origen = Auth::id();

        Auth::login($destino);

        // Fijar la sesión después del login: regenerar el id es lo que evita
        // que una sesión vieja quede válida con la identidad nueva.
        session()->regenerate();
        session([self::CLAVE => $origen]);

        CompanyContext::olvidar();
    }

    /** Vuelve a ser el superadministrador. Devuelve false si no había suplantación. */
    public static function terminar(): bool
    {
        $origen = session(self::CLAVE);

        if (! $origen) {
            return false;
        }

        $superadmin = User::withoutGlobalScopes()->find($origen);

        if (! $superadmin || ! $superadmin->isSuperadmin()) {
            // La cuenta de origen ya no existe o dejó de ser superadministrador:
            // se cierra la sesión en vez de dejarla como el usuario suplantado,
            // que es donde quedó a medias.
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            return false;
        }

        Auth::login($superadmin);
        session()->regenerate();
        session()->forget(self::CLAVE);

        CompanyContext::olvidar();

        return true;
    }

    public static function activa(): bool
    {
        return session()->has(self::CLAVE);
    }
}
