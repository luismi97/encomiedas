<?php

namespace Tests\Feature\Setup;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `sistema:tareas` — una sola entrada de cron para todo lo automático.
 *
 * Con dos líneas separadas es fácil configurar `schedule:run` y olvidar
 * `queue:work`, y sin la segunda ningún correo sale jamás: se acumulan en la
 * tabla `jobs` sin ningún error a la vista.
 */
class TareasProgramadasTest extends TestCase
{
    use RefreshDatabase;

    /** Deja un correo real esperando en la cola, como los del sistema. */
    private function encolarUnCorreo(): void
    {
        // En pruebas la cola es «sync» y se ejecutaría al instante; en el
        // servidor es «database», que es el caso que hay que reproducir.
        config(['queue.default' => 'database']);

        $usuario = \App\Models\User::create([
            'name' => 'Ana', 'username' => 'ana', 'email' => 'ana@t.test',
            'password' => bcrypt('x'), 'role' => \App\Models\User::ROLE_ADMIN, 'is_active' => true,
        ]);

        $usuario->notify(new \App\Notifications\RestablecerContrasena('un-token'));
    }

    public function test_corre_el_programador_y_la_cola(): void
    {
        $this->artisan('sistema:tareas')->assertSuccessful();
    }

    public function test_puede_correr_solo_el_programador(): void
    {
        $this->artisan('sistema:tareas', ['--sin-cola' => true])->assertSuccessful();
    }

    public function test_puede_procesar_solo_la_cola(): void
    {
        $this->artisan('sistema:tareas', ['--sin-programador' => true])->assertSuccessful();
    }

    /** Lo que de verdad importa: que los correos encolados salgan. */
    public function test_vacia_los_trabajos_pendientes(): void
    {
        $this->encolarUnCorreo();

        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('jobs')->count(),
            'La notificación va por cola: sin worker se queda acá.');

        $this->artisan('sistema:tareas')->assertSuccessful();

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('jobs')->count(),
            'Tras correr el comando la cola tiene que quedar vacía.');
    }

    public function test_informa_cuantos_proceso(): void
    {
        $this->encolarUnCorreo();

        $this->artisan('sistema:tareas')
            ->expectsOutputToContain('Trabajos procesados')
            ->assertSuccessful();
    }

    /**
     * El cron dispara cada minuto: si una corrida se pasa de ese tiempo, dos
     * workers competirían por los mismos trabajos.
     */
    public function test_no_se_solapan_dos_ejecuciones(): void
    {
        $candado = Cache::lock('sistema:tareas', 90);
        $candado->get();

        Queue::fake();

        $this->artisan('sistema:tareas')
            ->expectsOutputToContain('Ya hay una ejecución en curso')
            ->assertSuccessful();

        $candado->release();
    }

    /** Liberado el candado, la siguiente corrida entra normalmente. */
    public function test_tras_liberar_el_candado_vuelve_a_correr(): void
    {
        $candado = Cache::lock('sistema:tareas', 90);
        $candado->get();
        $candado->release();

        $this->artisan('sistema:tareas')
            ->doesntExpectOutputToContain('Ya hay una ejecución en curso')
            ->assertSuccessful();
    }

    /** Una corrida que revienta no puede dejar el candado tomado para siempre. */
    public function test_el_candado_se_libera_al_terminar(): void
    {
        $this->artisan('sistema:tareas')->assertSuccessful();

        $candado = Cache::lock('sistema:tareas', 90);
        $this->assertTrue($candado->get(), 'El candado quedó tomado tras una corrida normal.');
        $candado->release();
    }

    public function test_avisa_si_hay_trabajos_fallidos(): void
    {
        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'algo falló', 'failed_at' => now(),
        ]);

        $this->artisan('sistema:tareas')
            ->expectsOutputToContain('trabajos fallidos')
            ->assertSuccessful();
    }
}
