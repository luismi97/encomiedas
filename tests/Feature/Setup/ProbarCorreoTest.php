<?php

namespace Tests\Feature\Setup;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Diagnóstico de correo.
 *
 * El envío normal va por la cola: un error de credenciales termina en
 * `failed_jobs` y nunca en pantalla, así que desde fuera se ve igual que si
 * todo funcionara. Este comando envía en el acto y traduce el error.
 */
class ProbarCorreoTest extends TestCase
{
    public function test_rechaza_una_direccion_invalida(): void
    {
        $this->artisan('correo:probar', ['destino' => 'no-es-un-correo'])
            ->expectsOutputToContain('no es una dirección válida')
            ->assertFailed();
    }

    public function test_muestra_la_configuracion_en_uso(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.ejemplo.com',
            'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.username' => 'soporte@ejemplo.com',
        ]);
        Mail::fake();

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->expectsOutputToContain('mail.ejemplo.com')
            ->expectsOutputToContain('soporte@ejemplo.com')
            ->assertSuccessful();
    }

    /** Una contraseña vacía es un fallo silencioso muy común. */
    public function test_avisa_si_la_contrasena_esta_vacia(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.password' => '']);
        Mail::fake();

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->expectsOutputToContain('VACÍA');
    }

    public function test_avisa_cuando_el_mailer_es_log(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->expectsOutputToContain('NO salen')
            ->assertFailed();
    }

    public function test_envia_cuando_la_configuracion_sirve(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->expectsOutputToContain('Enviado sin errores')
            ->assertSuccessful();
    }

    // ── Conectividad ──────────────────────────────────────────────────

    /**
     * El caso del hosting que bloquea la salida SMTP: sin esta comprobación el
     * fallo se confunde con credenciales malas y se pierde tiempo ahí.
     */
    public function test_avisa_cuando_el_puerto_no_tiene_salida(): void
    {
        config([
            'mail.default' => 'smtp',
            // Puerto cerrado en el propio equipo: no depende de la red.
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 2599,
        ]);

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->expectsOutputToContain('SIN SALIDA')
            ->expectsOutputToContain('No es la contraseña')
            ->assertFailed();
    }

    /**
     * Ni siquiera intenta autenticarse si el puerto está cerrado.
     *
     * Se comprueba por la salida y no con un doble del facade: sustituirlo
     * desactiva la propia comprobación de red que se quiere verificar.
     */
    public function test_con_el_puerto_cerrado_no_intenta_enviar(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 2599,
        ]);

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->doesntExpectOutputToContain('Enviando a')
            ->assertFailed();
    }

    public function test_un_host_que_no_resuelve_se_reporta_aparte(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'no-existe-este-servidor-de-correo.invalid',
            'mail.mailers.smtp.port' => 465,
        ]);

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->expectsOutputToContain('no resuelve')
            ->assertFailed();
    }

    // ── La traducción de errores ──────────────────────────────────────

    public function test_un_fallo_de_autenticacion_explica_que_revisar(): void
    {
        config(['mail.default' => 'smtp']);

        Mail::shouldReceive('raw')->once()
            ->andThrow(new \RuntimeException('Expected response code "235" but got code "535", authentication failed'));

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->expectsOutputToContain('rechazó el usuario o la contraseña')
            ->expectsOutputToContain('correo completo')
            ->assertFailed();
    }

    public function test_un_fallo_de_conexion_sugiere_el_otro_puerto(): void
    {
        config(['mail.default' => 'smtp']);

        Mail::shouldReceive('raw')->once()
            ->andThrow(new \RuntimeException('Connection could not be established with host'));

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->expectsOutputToContain('bloqueada la salida')
            ->expectsOutputToContain('587')
            ->assertFailed();
    }

    public function test_un_fallo_de_cifrado_explica_el_scheme(): void
    {
        config(['mail.default' => 'smtp']);

        Mail::shouldReceive('raw')->once()
            ->andThrow(new \RuntimeException('SSL routines: wrong version number'));

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->expectsOutputToContain('smtps')
            ->assertFailed();
    }

    /** El servidor a veces devuelve la contraseña dentro del mensaje de error. */
    public function test_el_error_no_filtra_la_contrasena(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.password' => 'sup3r-secreta']);

        Mail::shouldReceive('raw')->once()
            ->andThrow(new \RuntimeException('auth failed with password sup3r-secreta'));

        $this->artisan('correo:probar', ['destino' => 'cliente@ejemplo.com'])
            ->doesntExpectOutputToContain('sup3r-secreta')
            ->expectsOutputToContain('********')
            ->assertFailed();
    }
}
