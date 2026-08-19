<?php

namespace Tests\Unit;

use App\Services\BarcodeService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * El código de barras se implementó a mano, así que no alcanza con mirar que
 * salga un dibujo: estas pruebas lo DECODIFICAN de vuelta, que es lo que hará
 * el lector del mostrador. Si la suma de control o la tabla de patrones
 * estuvieran mal, el round-trip falla.
 */
class BarcodeServiceTest extends TestCase
{
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

    private function servicio(): BarcodeService
    {
        return new BarcodeService();
    }

    /**
     * Lee los anchos como lo haría un lector: los parte en símbolos, los busca
     * en la tabla, verifica la suma de control y devuelve el texto.
     */
    private function decodificar(array $anchos): string
    {
        // La parada son 7 elementos (13 módulos); todos los demás símbolos, 6.
        $parada = implode('', array_splice($anchos, -7));
        $this->assertSame('2331112', $parada, 'Falta el símbolo de parada.');
        $this->assertSame(0, count($anchos) % 6, 'Quedaron módulos sueltos.');

        $simbolos = [];

        foreach (array_chunk($anchos, 6) as $grupo) {
            $patron = implode('', $grupo);
            $valor = array_search($patron, self::PATRONES, true);
            $this->assertNotFalse($valor, "Patrón desconocido: {$patron}");
            $simbolos[] = $valor;
        }

        $control = array_pop($simbolos);
        $inicio = array_shift($simbolos);
        $this->assertSame(104, $inicio, 'El código no arranca en Code 128-B.');

        $suma = $inicio;
        foreach ($simbolos as $posicion => $valor) {
            $suma += ($posicion + 1) * $valor;
        }

        $this->assertSame($suma % 103, $control, 'La suma de control no coincide.');

        return implode('', array_map(fn ($v) => chr($v + 32), $simbolos));
    }

    public function test_un_codigo_guia_se_lee_de_vuelta_igual(): void
    {
        $this->assertSame('SJ-LIM-00005', $this->decodificar($this->servicio()->modulos('SJ-LIM-00005')));
    }

    public function test_distintos_codigos_sobreviven_el_viaje(): void
    {
        foreach (['LIM-SJ-00001', 'ENC-000045', 'ALA-HER-99999', 'A1', 'PUN-LIB-00123'] as $codigo) {
            $this->assertSame($codigo, $this->decodificar($this->servicio()->modulos($codigo)));
        }
    }

    /** La suma de control es lo que evita que un borrón se lea como otro código. */
    public function test_cambiar_un_solo_caracter_cambia_la_suma_de_control(): void
    {
        $a = $this->servicio()->modulos('SJ-LIM-00005');
        $b = $this->servicio()->modulos('SJ-LIM-00006');

        $this->assertNotSame($a, $b);
    }

    public function test_cada_simbolo_mide_lo_que_manda_el_estandar(): void
    {
        $anchos = $this->servicio()->modulos('SJ-LIM-00005');

        // 12 caracteres + inicio + control = 14 símbolos de 11 módulos, más 13
        // de la parada.
        $this->assertSame(14 * 11 + 13, array_sum($anchos));
    }

    public function test_el_svg_lleva_barras_y_zona_de_silencio(): void
    {
        $svg = $this->servicio()->svg('SJ-LIM-00005');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertGreaterThan(20, substr_count($svg, '<rect'));

        // La primera barra no puede arrancar pegada al borde.
        preg_match('/<rect x="(\d+)" y="0"/', $svg, $m);
        $this->assertGreaterThan(0, (int) ($m[1] ?? 0), 'Falta la zona de silencio.');
    }

    /**
     * Decodifica el SVG ya dibujado, que es lo que el lector realmente ve.
     *
     * Las pruebas de arriba verifican la matemática; esta verifica el dibujo:
     * un error de alternancia entre barra y espacio daría anchos correctos y un
     * gráfico ilegible.
     */
    public function test_el_svg_dibujado_se_puede_decodificar(): void
    {
        $codigo = 'SJ-LIM-00005';
        $modulo = 2;
        $svg = $this->servicio()->svg($codigo, alto: 55, modulo: $modulo);

        preg_match_all('/<rect x="(\d+)" y="0" width="(\d+)"/', $svg, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'El SVG no dibujó ninguna barra.');

        // Reconstruye la secuencia barra/espacio a partir de la geometría.
        $anchos = [];
        $cursor = null;

        foreach ($m as $rect) {
            [$x, $ancho] = [(int) $rect[1], (int) $rect[2]];

            if ($cursor !== null && $x > $cursor) {
                $anchos[] = ($x - $cursor) / $modulo; // espacio
            }

            $anchos[] = $ancho / $modulo;             // barra
            $cursor = $x + $ancho;
        }

        // El último elemento del patrón de parada es una barra, así que la
        // secuencia termina justo donde la dejó el bucle.
        $this->assertSame($codigo, $this->decodificar(array_map('intval', $anchos)));
    }

    public function test_no_codifica_lo_que_el_lector_no_podria_leer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->servicio()->modulos('SJ-LIM-ñ');
    }

    public function test_no_codifica_una_cadena_vacia(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->servicio()->modulos('');
    }
}
