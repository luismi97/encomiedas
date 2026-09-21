<?php

namespace Tests\Feature\Ui;

use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use Tests\TestCase;

/**
 * Las clases de color que viven en constantes PHP tienen que llegar al CSS.
 *
 * Tailwind purga lo que no encuentra escaneando; su lista de rutas no incluía
 * app/, así que «En camino», «Enviado» y «Llegó al destino» se pintaban sin
 * fondo, y los colores de rol que se ven en el listado de usuarios tampoco
 * existían. Los tests del modelo pasaban igual: comprobaban la constante, no
 * lo que el navegador recibe.
 */
class ClasesCompiladasTest extends TestCase
{
    /** @return array<int,string> */
    private function clasesDeColor(): array
    {
        $constantes = array_merge(
            array_values(Invoice::STATUS_BADGE_CLASSES),
            array_values(User::ROLE_BADGE_CLASSES),
            array_values(Dispatch::STATUS_BADGE_CLASSES),
        );

        $clases = [];

        foreach ($constantes as $lista) {
            foreach (preg_split('/\s+/', $lista, -1, PREG_SPLIT_NO_EMPTY) as $clase) {
                // Solo las de color plano: las variantes dark: y las opacidades
                // (bg-x/40) se generan aparte y no valen como comprobación.
                if (preg_match('/^(bg|text)-[a-z]+-\d{2,3}$/', $clase)) {
                    $clases[] = $clase;
                }
            }
        }

        return array_values(array_unique($clases));
    }

    public function test_todo_color_de_badge_existe_en_el_css_compilado(): void
    {
        $hojas = glob(public_path('build/assets/*.css'));

        if (! $hojas) {
            $this->markTestSkipped('No hay CSS compilado: corré npm run build.');
        }

        $css = implode('', array_map('file_get_contents', $hojas));
        $faltantes = [];

        foreach ($this->clasesDeColor() as $clase) {
            // Tailwind escapa la barra, pero estas no la llevan.
            if (! str_contains($css, '.' . $clase)) {
                $faltantes[] = $clase;
            }
        }

        $this->assertSame([], $faltantes,
            'Estas clases se purgaron y su badge sale sin color: ' . implode(', ', $faltantes)
            . '. Revisá que tailwind.config.js escanee app/.');
    }

    /** La causa de raíz, por si alguien recorta las rutas del config. */
    public function test_tailwind_escanea_las_clases_que_viven_en_php(): void
    {
        $config = file_get_contents(base_path('tailwind.config.js'));

        $this->assertMatchesRegularExpression("#'\./app/\*\*/\*\.php'#", $config,
            'tailwind.config.js dejó de escanear app/: los colores definidos en constantes se purgarán.');
    }

    /**
     * El bundle que está en el repositorio tiene que corresponder a las vistas
     * que están en el repositorio.
     *
     * Esto ya pasó una vez y en producción: se desplegaron las vistas nuevas y
     * el CSS viejo, y los tooltips de ayuda salieron sin forma, porque sus clases
     * no existían en ese archivo. El servidor es php:8.2-apache sin Node, así que
     * no puede compilar: el build viaja versionado y esta prueba es la que avisa
     * cuando quedó atrás.
     *
     * Se comprueban clases poco comunes a propósito: son las que solo aparecen
     * en una vista nueva y por lo tanto las primeras en faltar.
     */
    public function test_el_css_compilado_esta_al_dia_con_las_vistas(): void
    {
        $hojas = glob(public_path('build/assets/*.css'));

        if (! $hojas) {
            $this->markTestSkipped('No hay CSS compilado: corré npm run build.');
        }

        $css = implode('', array_map('file_get_contents', $hojas));

        // Clase => para qué sirve, para que el mensaje diga qué se rompe.
        $imprescindibles = [
            'bottom-full'  => 'el globo del tooltip se posicione sobre el botón',
            'w-64'         => 'el globo del tooltip tenga ancho',
            'leading-none' => 'el signo de interrogación quepa en su círculo',
            'ring-brand-400' => 'el recorrido guiado resalte el control del que habla',
        ];

        $faltantes = [];

        foreach ($imprescindibles as $clase => $paraQue) {
            // Tailwind escapa los corchetes y las barras; estas no los llevan.
            if (! str_contains($css, '.' . $clase)) {
                $faltantes[] = "{$clase} (hace que {$paraQue})";
            }
        }

        $this->assertSame([], $faltantes,
            'El CSS compilado quedó atrás de las vistas. Corré «npm run build» y versioná '
            . 'public/build, o estas pantallas salen sin estilos en producción. Falta: '
            . implode('; ', $faltantes));
    }
}
