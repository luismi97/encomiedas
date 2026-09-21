<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cómo se busca texto libre en una tabla grande.
 *
 * `LIKE '%juan%'` no puede usar ningún índice: el comodín inicial obliga a leer
 * la tabla entera. Con tres mil guías diarias eso es un millón de filas al año,
 * y cada tecla del buscador las recorría todas.
 *
 * En MySQL se usa el índice FULLTEXT, que para eso existe. En SQLite —donde
 * corren las pruebas— no hay equivalente, así que se cae al LIKE de siempre:
 * con las decenas de filas de una prueba da igual, y lo que importa es que la
 * consulta signifique lo mismo en los dos lados.
 *
 * El corte de tres caracteres no es un capricho: InnoDB no indexa palabras más
 * cortas que `innodb_ft_min_token_size` (3 por defecto), así que un FULLTEXT de
 * dos letras no devuelve nada. Por debajo de eso se busca por prefijo, que sí
 * entra por el índice normal de la columna.
 */
class BusquedaDeTexto
{
    /** Menos de esto no se busca: la primera tecla traería media tabla. */
    public const MINIMO = 2;

    /** A partir de acá vale la pena el índice de texto completo. */
    public const MINIMO_FULLTEXT = 3;

    public static function esBuscable(?string $termino): bool
    {
        return mb_strlen(trim((string) $termino)) >= self::MINIMO;
    }

    /**
     * Agrega al grupo la búsqueda de $termino sobre $columnas.
     *
     * Se llama SIEMPRE dentro de un `where(function ($q) { ... })` y suma con
     * `orWhere`: quien llama decide con qué otras condiciones convive.
     *
     * @param  array<int,string>  $columnas
     */
    public static function agregar(Builder $consulta, array $columnas, string $termino): Builder
    {
        $termino = trim($termino);

        if (! self::esBuscable($termino)) {
            return $consulta;
        }

        if (self::usaFullText($termino)) {
            return $consulta->orWhereFullText($columnas, self::booleano($termino), ['mode' => 'boolean']);
        }

        // Con menos de tres letras el índice de texto no sirve, así que en MySQL
        // se busca solo por prefijo: el comodín inicial costaría recorrer la
        // tabla entera, y es justo lo que esto viene a evitar.
        $soloPrefijo = DB::connection()->getDriverName() === 'mysql';

        // `orWhere` y no `where`: quien llama ya viene sumando condiciones con
        // OR, y un AND acá ataría el nombre a que además coincidiera la cédula.
        return $consulta->orWhere(function (Builder $sub) use ($columnas, $termino, $soloPrefijo) {
            foreach ($columnas as $columna) {
                $sub->orWhere($columna, 'like', $termino . '%');

                if (! $soloPrefijo) {
                    // Principio de cualquier palabra, que es lo que hace el
                    // FULLTEXT de MySQL: sin esto «Solano» no encontraría a
                    // «Marta Solano» y las pruebas dirían algo distinto de lo
                    // que pasa en producción.
                    $sub->orWhere($columna, 'like', '% ' . $termino . '%');
                }
            }
        });
    }

    private static function usaFullText(string $termino): bool
    {
        return DB::connection()->getDriverName() === 'mysql'
            && mb_strlen($termino) >= self::MINIMO_FULLTEXT;
    }

    /**
     * El término en sintaxis booleana de MySQL: toda palabra obligatoria y
     * abierta por el final, para que «fall» encuentre «Fallas».
     *
     * Los operadores del propio modo booleano se quitan antes: un `+` o un `~`
     * escritos por el cajero cambiarían el sentido de la consulta, y un `"`
     * suelto la deja sin cerrar y MySQL no devuelve nada.
     */
    private static function booleano(string $termino): string
    {
        $limpio = preg_replace('/[+\-><()~*"@]+/u', ' ', $termino);
        $palabras = preg_split('/\s+/u', trim($limpio), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $palabras = array_filter(
            $palabras,
            fn (string $p) => mb_strlen($p) >= self::MINIMO_FULLTEXT
        );

        if ($palabras === []) {
            // Todo lo que escribió era demasiado corto para el índice: se manda
            // algo que no puede coincidir, en vez de una consulta vacía que
            // devolvería la tabla entera.
            return '+' . str_repeat('z', 64);
        }

        return implode(' ', array_map(fn (string $p) => '+' . $p . '*', $palabras));
    }
}
