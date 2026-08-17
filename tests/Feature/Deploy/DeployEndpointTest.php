<?php

namespace Tests\Feature\Deploy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeployEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'un-token-de-prueba-suficientemente-largo-para-pasar-el-minimo';

    protected function setUp(): void
    {
        parent::setUp();

        // El contador de fallos vive en la caché de archivos, que persiste
        // entre pruebas: sin limpiarlo, unas contaminan a otras.
        \Illuminate\Support\Facades\Cache::store('file')->flush();
    }

    private function habilitar(): void
    {
        config(['app.deploy_token' => $this->token]);
    }

    /** Sin token configurado el endpoint no debe ni existir. */
    public function test_sin_token_en_el_env_responde_404(): void
    {
        config(['app.deploy_token' => null]);

        $this->get('/__deploy/status?token=loquesea')->assertNotFound();
    }

    public function test_con_token_equivocado_responde_404_y_no_403(): void
    {
        $this->habilitar();

        // 403 delataría que el endpoint existe y que solo falta acertar el token.
        $this->get('/__deploy/status?token=incorrecto')->assertNotFound();
    }

    public function test_un_token_corto_no_habilita_el_endpoint(): void
    {
        config(['app.deploy_token' => '1234']);

        $this->get('/__deploy/status?token=1234')->assertNotFound();
    }

    public function test_el_token_se_acepta_por_cabecera(): void
    {
        $this->habilitar();

        $this->withHeader('X-Deploy-Token', $this->token)
            ->get('/__deploy/status')
            ->assertOk()
            ->assertJsonStructure(['entorno', 'migraciones_pendientes', 'cola']);
    }

    public function test_una_accion_fuera_de_la_lista_no_se_ejecuta(): void
    {
        $this->habilitar();

        // La ruta solo acepta las acciones enumeradas: nada de comandos arbitrarios.
        foreach (['db:wipe', 'tinker', 'migrate:fresh', 'cualquier-cosa'] as $accion) {
            $this->get("/__deploy/{$accion}?token={$this->token}")->assertNotFound();
        }
    }

    public function test_status_no_cambia_nada(): void
    {
        $this->habilitar();

        $this->get("/__deploy/status?token={$this->token}")
            ->assertOk()
            ->assertJsonPath('migraciones_pendientes', 0);
    }

    public function test_clear_limpia_la_cache(): void
    {
        $this->habilitar();

        $this->get("/__deploy/clear?token={$this->token}")
            ->assertOk()
            ->assertJsonPath('accion', 'clear')
            ->assertJsonStructure(['ejecutado' => ['optimize:clear']]);
    }

    public function test_el_diagnostico_de_hacienda_no_expone_credenciales(): void
    {
        $this->habilitar();

        $respuesta = $this->get("/__deploy/hacienda?token={$this->token}")->assertOk();
        $cuerpo = $respuesta->getContent();

        $this->assertStringNotContainsString('atv_password', $cuerpo);
        $this->assertStringNotContainsString('certificate_pin', $cuerpo);
        $respuesta->assertJsonStructure(['listo_para_emitir', 'requisitos', 'ambiente']);
    }

    public function test_el_seeder_exige_confirmacion_explicita(): void
    {
        $this->habilitar();

        $this->get("/__deploy/seed?token={$this->token}")
            ->assertStatus(428)
            ->assertJsonFragment(['error' => 'Falta &confirm=1. Esto sobrescribe la configuración de la empresa con datos '
                . 'de demostración y crea facturas ficticias.']);
    }

    public function test_el_seeder_se_niega_en_produccion(): void
    {
        $this->habilitar();
        app()->detectEnvironment(fn () => 'production');

        $this->get("/__deploy/seed?token={$this->token}&confirm=1")
            ->assertStatus(409)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'no corren en producción'));
    }

    public function test_el_uso_queda_registrado(): void
    {
        $this->habilitar();

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($mensaje, $ctx) => $mensaje === 'Endpoint de mantenimiento usado' && $ctx['accion'] === 'status');

        $this->get("/__deploy/status?token={$this->token}");
    }

    /**
     * La ruta va fuera del grupo `web` a propósito: ese grupo arranca la sesión,
     * y con SESSION_DRIVER=database la sesión necesita la base. Un endpoint para
     * crear la base no puede depender de la base.
     */
    public function test_la_ruta_no_arrastra_middleware_de_sesion(): void
    {
        $ruta = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => str_contains($r->uri(), '__deploy'));

        $this->assertNotNull($ruta, 'No se encontró la ruta de mantenimiento.');

        $middleware = $ruta->gatherMiddleware();

        $this->assertNotContains('web', $middleware);
        $this->assertEmpty(
            array_filter($middleware, fn ($m) => is_string($m) && str_contains($m, 'Session')),
            'La ruta arrastra middleware de sesión: fallaría sin base de datos.'
        );
        $this->assertEmpty(
            array_filter($middleware, fn ($m) => is_string($m) && str_contains($m, 'ThrottleRequests')),
            'El throttle va contra la caché, que también vive en la base.'
        );
    }

    /** El límite usa la caché de archivos, no la de la aplicación. */
    public function test_limita_los_intentos_por_minuto(): void
    {
        $this->habilitar();
        \Illuminate\Support\Facades\Cache::store('file')->flush();

        for ($i = 0; $i < 10; $i++) {
            $this->get('/__deploy/status?token=incorrecto')->assertNotFound();
        }

        $this->get('/__deploy/status?token=incorrecto')->assertStatus(429);
    }

    public function test_db_create_esta_en_la_lista_de_acciones(): void
    {
        $this->habilitar();

        // En pruebas la conexión es SQLite en memoria: no hay archivo que crear,
        // pero la acción debe existir y responder.
        $this->get("/__deploy/db-create?token={$this->token}")
            ->assertOk()
            ->assertJsonStructure(['creada', 'salida']);
    }

    public function test_el_comando_genera_un_token_largo(): void
    {
        Artisan::call('deploy:token');
        $salida = Artisan::output();

        $this->assertStringContainsString('DEPLOY_TOKEN=', $salida);
        preg_match('/DEPLOY_TOKEN=(\S+)/', $salida, $m);
        $this->assertGreaterThanOrEqual(64, strlen($m[1] ?? ''));
    }
}
