<?php

namespace Tests\Feature\Setup;

use Tests\TestCase;

/**
 * `cron.php`, para paneles que solo aceptan un archivo PHP o una URL.
 *
 * Cloudways no permite un comando de shell en sus tareas programadas, así que
 * la línea de cron habitual no sirve: hace falta un archivo que arranque
 * Laravel por su cuenta.
 */
class CronPhpTest extends TestCase
{
    private function contenido(): string
    {
        return file_get_contents(base_path('cron.php'));
    }

    public function test_el_archivo_existe_en_la_raiz(): void
    {
        $this->assertFileExists(base_path('cron.php'));
    }

    /** En public/ quedaría accesible desde internet. */
    public function test_no_esta_en_la_carpeta_publica(): void
    {
        $this->assertFileDoesNotExist(public_path('cron.php'),
            'Un disparador de trabajos no puede vivir en la carpeta pública.');
    }

    public function test_arranca_laravel_y_llama_al_comando(): void
    {
        $php = $this->contenido();

        $this->assertStringContainsString('vendor/autoload.php', $php);
        $this->assertStringContainsString('bootstrap/app.php', $php);
        $this->assertStringContainsString('sistema:tareas', $php);
    }

    /** Si alguien lo expone por web, no puede quedar abierto. */
    public function test_por_web_exige_el_token_de_despliegue(): void
    {
        $php = $this->contenido();

        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $php);
        $this->assertStringContainsString('hash_equals', $php);
        $this->assertStringContainsString('http_response_code(404)', $php);
    }

    /**
     * Sin código de salida distinto de cero, el cron da por buena una corrida
     * que no hizo nada, y el fallo pasa inadvertido durante días.
     */
    public function test_devuelve_error_al_cron_si_algo_falla(): void
    {
        $php = $this->contenido();

        $this->assertStringContainsString('catch (Throwable $e)', $php);
        $this->assertStringContainsString('$codigo = 1', $php);
        $this->assertStringContainsString('exit($codigo)', $php);
    }

    /** El archivo tiene que ser PHP válido: un error de sintaxis mata el cron. */
    public function test_no_tiene_errores_de_sintaxis(): void
    {
        exec('php -l ' . escapeshellarg(base_path('cron.php')), $salida, $codigo);

        $this->assertSame(0, $codigo, implode("\n", $salida));
    }
}
