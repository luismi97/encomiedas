<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\CashSession;
use App\Models\User;
use App\Services\CajaService;

/**
 * Abre el turno de caja de una sede.
 *
 * Cobrar de contado exige una caja abierta, así que cualquier prueba que guarde
 * una guía pagada necesita una. Va acá y no repetido en cada archivo para que
 * se lea como lo que es —el mostrador con la caja abierta— y no como plomería.
 */
trait AbreLaCaja
{
    protected function abrirCajaDe(Branch $sede, User $usuario, float $fondo = 0): CashSession
    {
        return app(CajaService::class)->abrir(
            $sede->cashRegisters()->firstOrFail(),
            $usuario,
            $fondo
        );
    }
}
