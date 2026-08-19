<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Código de barras Code 128-B para la etiqueta que se pega al paquete.
 *
 * Va implementado aquí y no con una librería porque el hosting es cPanel sin
 * SSH: no hay forma de correr composer allá, y una dependencia más es una
 * carpeta vendor que hay que volver a subir entera en cada despliegue.
 *
 * Code 128-B cubre el juego que usan los códigos guía (letras mayúsculas,
 * dígitos y guion) y lo lee cualquier lector de mostrador.
 */
class BarcodeService
{
    /**
     * Patrones del estándar: cada símbolo son anchos alternados de barra y
     * espacio, empezando por barra. Suman 11 módulos, salvo el de parada.
     */
    private const PATRONES = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    private const INICIO_B = 104;
    private const PARADA = 106;

    /** Módulos en blanco a cada lado. Sin ellos el lector no engancha. */
    private const ZONA_SILENCIO = 10;

    /**
     * Anchos de barra y espacio del código completo, alternando: el primero es
     * barra. Es la representación de la que salen tanto el SVG como cualquier
     * otra forma de dibujarlo.
     *
     * @return array<int,int>
     */
    public function modulos(string $contenido): array
    {
        if ($contenido === '') {
            throw new InvalidArgumentException('No hay nada que codificar.');
        }

        $valores = [];

        foreach (str_split($contenido) as $caracter) {
            $ascii = ord($caracter);

            // Code 128-B va del espacio (32) al DEL (127).
            if ($ascii < 32 || $ascii > 127) {
                throw new InvalidArgumentException(
                    "El carácter «{$caracter}» no se puede representar en Code 128-B."
                );
            }

            $valores[] = $ascii - 32;
        }

        // Suma de control: el estándar pondera cada símbolo por su posición.
        $suma = self::INICIO_B;

        foreach ($valores as $posicion => $valor) {
            $suma += ($posicion + 1) * $valor;
        }

        $simbolos = array_merge([self::INICIO_B], $valores, [$suma % 103], [self::PARADA]);

        $anchos = [];

        foreach ($simbolos as $simbolo) {
            foreach (str_split(self::PATRONES[$simbolo]) as $ancho) {
                $anchos[] = (int) $ancho;
            }
        }

        return $anchos;
    }

    /**
     * SVG listo para incrustar. Se dibuja al vuelo y no se guarda: es
     * determinista a partir del código, así que un archivo por guía sería un
     * caché que hay que invalidar sin ganar nada.
     *
     * @param int $modulo Ancho de la barra más fina, en píxeles.
     */
    public function svg(string $contenido, int $alto = 60, int $modulo = 2): string
    {
        $anchos = $this->modulos($contenido);
        $totalModulos = array_sum($anchos) + (self::ZONA_SILENCIO * 2);

        $ancho = $totalModulos * $modulo;
        $x = self::ZONA_SILENCIO * $modulo;

        $barras = '';

        foreach ($anchos as $indice => $modulos) {
            $ancho_ = $modulos * $modulo;

            // Índice par = barra; impar = espacio. Solo se dibujan las barras.
            if ($indice % 2 === 0) {
                $barras .= sprintf(
                    '<rect x="%d" y="0" width="%d" height="%d" fill="#000"/>',
                    $x, $ancho_, $alto
                );
            }

            $x += $ancho_;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" '
            . 'shape-rendering="crispEdges" role="img" aria-label="Código de barras %s">'
            . '<rect width="%d" height="%d" fill="#fff"/>%s</svg>',
            $ancho, $alto, $ancho, $alto, e($contenido), $ancho, $alto, $barras
        );
    }
}
