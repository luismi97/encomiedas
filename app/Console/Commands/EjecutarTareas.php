<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Todo lo que el servidor tiene que hacer solo, en un único comando.
 *
 * Sustituye a dos líneas de cron —`schedule:run` y `queue:work`— por una. No es
 * solo comodidad: con dos entradas separadas es fácil configurar una y olvidar
 * la otra, y olvidar la de la cola significa que ningún correo sale nunca, sin
 * ningún error a la vista.
 *
 * La frecuencia la decide quien lo programa. El comando no la asume: procesa
 * lo que haya y termina, y un candado impide que dos ejecuciones se solapen si
 * una tarda más de lo que tarda en volver a dispararse.
 */
class EjecutarTareas extends Command
{
    protected $signature = 'sistema:tareas
        {--max-time=240 : Tope de seguridad en segundos: sale aunque queden trabajos.}
        {--sin-cola : Solo corre las tareas programadas.}
        {--sin-programador : Solo procesa la cola.}';

    protected $description = 'Ejecuta las tareas programadas y procesa la cola de envíos';

    /**
     * Vida del candado. Es un tope de seguridad, no una cadencia: si una
     * corrida se cuelga, expira sola y la siguiente puede entrar.
     */
    private const SEGUNDOS_DE_CANDADO = 300;

    public function handle(): int
    {
        // Dos ejecuciones a la vez competirían por los mismos trabajos. Con el
        // candado da igual cada cuánto lo dispare el panel.
        $candado = Cache::lock('sistema:tareas', self::SEGUNDOS_DE_CANDADO);

        try {
            $candado->block(0);
        } catch (LockTimeoutException $e) {
            $this->components->warn('Ya hay una ejecución en curso: esta se omite.');

            return self::SUCCESS;
        }

        try {
            if (! $this->option('sin-programador')) {
                $this->correrProgramador();
            }

            if (! $this->option('sin-cola')) {
                $this->procesarCola();
            }
        } finally {
            $candado->release();
        }

        return self::SUCCESS;
    }

    /** Hacienda cada minuto, desecho y corte de crédito a su hora. */
    private function correrProgramador(): void
    {
        $this->call('schedule:run');
    }

    /**
     * Vacía la cola y termina.
     *
     * --stop-when-empty y no un worker permanente: un proceso que no muere se
     * queda con el código viejo en memoria después de cada despliegue.
     */
    private function procesarCola(): void
    {
        $antes = $this->pendientes();

        $this->call('queue:work', [
            // Termina en cuanto la cola queda vacía; el --max-time es solo un
            // tope por si entra una avalancha de trabajos.
            '--stop-when-empty' => true,
            '--max-time' => (int) $this->option('max-time'),
            '--tries' => 3,
            '--no-interaction' => true,
        ]);

        $despues = $this->pendientes();

        if ($antes > 0) {
            $this->components->twoColumnDetail(
                'Trabajos procesados',
                max(0, $antes - $despues) . ' de ' . $antes
            );
        }

        if ($fallidos = $this->fallidos()) {
            $this->components->warn("Hay {$fallidos} trabajos fallidos. Revisalos con: php artisan queue:failed");
        }
    }

    private function pendientes(): int
    {
        return $this->contar('jobs');
    }

    private function fallidos(): int
    {
        return $this->contar('failed_jobs');
    }

    /** La tabla puede no existir todavía en una instalación a medio migrar. */
    private function contar(string $tabla): int
    {
        try {
            return DB::table($tabla)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
