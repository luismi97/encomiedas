<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Latido del modo sin conexión: 204 y nada más.
 *
 * Lo consume el watchdog del layout cada pocos segundos. Mide si hay RED hasta
 * el servidor, no si la aplicación está sana: por eso va fuera del grupo web
 * (ver bootstrap/app.php) y no toca sesión ni base de datos.
 *
 * Es una clase y no una closure porque `route:cache` no serializa closures.
 */
class PingController
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
