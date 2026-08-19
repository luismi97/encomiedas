<?php

namespace Tests\Feature\Deploy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Verificador de correo del endpoint de despliegue.
 *
 * Sin SSH no hay forma de saber por qué no sale un correo: el envío real va por
 * la cola, así que un error de credenciales termina en failed_jobs y nunca en
 * pantalla. Esto envía en el acto y devuelve el error del servidor.
 */
class PruebaDeCorreoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'un-token-de-al-menos-treinta-y-dos-caracteres';

    protected function setUp(): void
    {
        parent::setUp();

        // El contador de intentos fallidos vive en la caché de ARCHIVOS, que
        // sobrevive entre pruebas: sin limpiarlo, la primera prueba —que falla
        // a propósito— deja el cupo gastado para las demás.
        \Illuminate\Support\Facades\Cache::store('file')->flush();

        config(['app.deploy_token' => self::TOKEN]);
    }

    private function url(array $params = []): string
    {
        return '/__deploy/mail-test?' . http_build_query(['token' => self::TOKEN] + $params);
    }

    public function test_sin_token_no_existe(): void
    {
        $this->get('/__deploy/mail-test?to=a@b.com')->assertNotFound();
    }

    public function test_hace_falta_a_donde_mandarla(): void
    {
        config(['mail.default' => 'smtp']);

        $this->getJson($this->url())
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'to=correo@ejemplo.com'));
    }

    public function test_un_correo_invalido_se_rechaza(): void
    {
        config(['mail.default' => 'smtp']);

        $this->getJson($this->url(['to' => 'no-es-correo']))->assertStatus(422);
    }

    /** El caso que confunde: «enviado» pero el correo fue al log. */
    public function test_avisa_cuando_el_mailer_es_log(): void
    {
        config(['mail.default' => 'log']);

        $this->getJson($this->url(['to' => 'cliente@ejemplo.com']))
            ->assertStatus(409)
            ->assertJsonPath('enviado', false)
            ->assertJsonPath('mailer', 'log')
            ->assertJsonPath('problema', fn ($p) => str_contains($p, 'NO salen'));
    }

    public function test_con_smtp_configurado_envia(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->getJson($this->url(['to' => 'cliente@ejemplo.com']))
            ->assertOk()
            ->assertJsonPath('enviado', true)
            ->assertJsonPath('a', 'cliente@ejemplo.com');
    }

    /** Un fallo del servidor de correo tiene que llegar legible, no como 500. */
    public function test_un_fallo_de_smtp_se_reporta_con_su_motivo(): void
    {
        config(['mail.default' => 'smtp']);

        Mail::shouldReceive('raw')
            ->once()
            ->andThrow(new \RuntimeException('Connection could not be established'));

        $this->getJson($this->url(['to' => 'cliente@ejemplo.com']))
            ->assertStatus(502)
            ->assertJsonPath('enviado', false)
            ->assertJsonPath('problema', fn ($p) => str_contains($p, 'Connection could not be established'));
    }

    /**
     * La ruta tiene su propia lista blanca además de la del controlador.
     * Agregar una acción en una sola de las dos da un 404 sin explicación.
     */
    public function test_la_ruta_y_el_controlador_conocen_las_mismas_acciones(): void
    {
        preg_match(
            "/->where\('action', '([^']+)'\)/",
            file_get_contents(base_path('routes/deploy.php')),
            $m
        );

        $enLaRuta = explode('|', $m[1] ?? '');

        $reflexion = new \ReflectionClass(\App\Http\Controllers\DeployController::class);
        $enElControlador = array_merge(
            array_keys($reflexion->getConstant('SEQUENCES')),
            $reflexion->getConstant('REPORTS'),
        );

        sort($enLaRuta);
        sort($enElControlador);

        $this->assertSame($enElControlador, $enLaRuta,
            'La lista de acciones de routes/deploy.php no coincide con la del controlador.');
    }

    /** El mensaje del servidor a veces trae la contraseña: no puede salir. */
    public function test_la_respuesta_no_filtra_la_contrasena(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.password' => 'sup3r-secreta',
        ]);

        Mail::shouldReceive('raw')
            ->once()
            ->andThrow(new \RuntimeException('Auth failed for user with password sup3r-secreta'));

        $respuesta = $this->getJson($this->url(['to' => 'cliente@ejemplo.com']))->assertStatus(502);

        $this->assertStringNotContainsString('sup3r-secreta', $respuesta->getContent());
        $this->assertStringContainsString('***', $respuesta->getContent());
    }
}
