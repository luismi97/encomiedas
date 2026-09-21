<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Listados que crecen hacia abajo en vez de paginar.
 *
 * El motivo no es estético. `paginate()` lanza un `select count(*)` sobre el
 * conjunto filtrado además de la consulta de la página, y ese conteo es lo caro:
 * con tres mil guías diarias la tabla pasa el millón de filas en un año, y cada
 * tecla del buscador obligaba a contarlas todas para decidir cuántos números
 * poner al pie. El número de página nadie lo usa —quien busca una guía busca por
 * código, no por página 4.783—.
 *
 * Acá se piden `visibles + 1` filas: si vuelve la de más, hay más, y con eso
 * alcanza para saber si seguir mostrando el pie. Nunca se cuenta nada.
 *
 * El tope existe porque el scroll infinito sin freno termina con el navegador
 * renderizando cincuenta mil filas: pasado el límite se le pide al operario que
 * acote el filtro, que es lo que de verdad iba a encontrarle lo que busca.
 */
trait ScrollInfinito
{
    /** Cuántas filas se están mostrando. Crece de tanda en tanda. */
    public int $visibles = 0;

    /** Filas por tanda. Se puede pisar por componente. */
    protected function porTanda(): int
    {
        return 50;
    }

    /** Máximo que se deja acumular en pantalla. */
    protected function topeDeScroll(): int
    {
        return 500;
    }

    public function mountScrollInfinito(): void
    {
        $this->visibles = $this->porTanda();
    }

    public function cargarMas(): void
    {
        $this->visibles = min($this->visibles + $this->porTanda(), $this->topeDeScroll());
    }

    /**
     * Vuelve a la primera tanda.
     *
     * Va en todo cambio de filtro: si no, filtrar con 500 filas cargadas pide
     * 500 filas del filtro nuevo, que es justo la consulta pesada que esto
     * venía a evitar.
     */
    public function reiniciarScroll(): void
    {
        $this->visibles = $this->porTanda();
    }

    /**
     * La tanda actual y si queda algo debajo.
     *
     * @return array{items: Collection, hayMas: bool, enElTope: bool, visibles: int}
     */
    protected function tanda(Builder $consulta): array
    {
        // Un componente recién montado sin mount() propio no pasó por el trait.
        $visibles = $this->visibles > 0 ? $this->visibles : $this->porTanda();

        // La fila de más es toda la detección: si viene, hay más.
        $filas = $consulta->limit($visibles + 1)->get();
        $hayMas = $filas->count() > $visibles;

        return [
            'items'    => $hayMas ? $filas->take($visibles) : $filas,
            'hayMas'   => $hayMas,
            'enElTope' => $hayMas && $visibles >= $this->topeDeScroll(),
            'visibles' => $visibles,
        ];
    }
}
