<?php

namespace Tests\Feature\Deploy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Diagnóstico del esquema y salida de emergencia.
 *
 * `migrate` falla con «Table users already exists» cuando el esquema y la tabla
 * `migrations` no coinciden: las tablas están, pero Laravel no registra
 * haberlas creado. Sin consola no había forma de ver las dos listas juntas ni
 * de salir del bloqueo.
 */
class DiagnosticoDeBaseTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'un-token-de-al-menos-treinta-y-dos-caracteres';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::store('file')->flush();
        config(['app.deploy_token' => self::TOKEN]);
    }

    private function url(string $accion, array $params = []): string
    {
        return "/__deploy/{$accion}?" . http_build_query(['token' => self::TOKEN] + $params);
    }

    // ── Diagnóstico ───────────────────────────────────────────────────

    public function test_sin_token_no_existe(): void
    {
        $this->get('/__deploy/db-status')->assertNotFound();
        $this->get('/__deploy/migrate-fresh')->assertNotFound();
    }

    public function test_reporta_las_tablas_y_las_migraciones(): void
    {
        $r = $this->getJson($this->url('db-status'))->assertOk();

        $r->assertJsonPath('migraciones.tabla_existe', true);
        $this->assertGreaterThan(0, $r->json('tablas.cantidad'));
        $this->assertGreaterThan(0, $r->json('migraciones.registradas'));
    }

    public function test_con_todo_al_dia_lo_dice(): void
    {
        $this->getJson($this->url('db-status'))
            ->assertJsonPath('migraciones.pendientes', 0)
            ->assertJsonPath('diagnostico', fn ($d) => str_contains($d, 'Todo al día'));
    }

    /** El caso del usuario: tablas sin registro de migraciones. */
    public function test_detecta_el_esquema_sin_registro(): void
    {
        DB::table('migrations')->delete();

        $r = $this->getJson($this->url('db-status'))->assertOk();

        $r->assertJsonPath('migraciones.registradas', 0);
        $this->assertStringContainsString('NINGUNA migración registrada', $r->json('diagnostico'));
        $this->assertStringContainsString('migrate-fresh', $r->json('diagnostico'));
    }

    public function test_nombra_las_migraciones_que_faltan(): void
    {
        $ultima = DB::table('migrations')->orderByDesc('id')->first();
        DB::table('migrations')->where('id', $ultima->id)->delete();

        $r = $this->getJson($this->url('db-status'))->assertOk();

        $this->assertSame(1, $r->json('migraciones.pendientes'));
        $this->assertSame([$ultima->migration], $r->json('migraciones.lista_pendientes'));
    }

    // ── La salida de emergencia ───────────────────────────────────────

    /** Borra todo: no puede dispararse por curiosear la URL. */
    public function test_reconstruir_exige_confirmacion_explicita(): void
    {
        $this->getJson($this->url('migrate-fresh'))
            ->assertStatus(428)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'BORRA TODAS LAS TABLAS'));

        // Las tablas siguen ahí.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('users'));
    }

    public function test_una_confirmacion_a_medias_no_alcanza(): void
    {
        $this->getJson($this->url('migrate-fresh', ['confirm' => '1']))->assertStatus(428);
        $this->getJson($this->url('migrate-fresh', ['confirm' => 'si']))->assertStatus(428);

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('users'));
    }

    /**
     * Se comprueba la orden que se emite, no su ejecución: en SQLite,
     * `migrate:fresh` termina con un VACUUM que no puede correr dentro de la
     * transacción de RefreshDatabase. En MySQL —el motor de producción— no
     * existe ese límite.
     */
    public function test_con_la_frase_exacta_reconstruye(): void
    {
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->once()
            ->with('migrate:fresh', ['--force' => true]);

        \Illuminate\Support\Facades\Artisan::shouldReceive('output')
            ->andReturn('Migrations rebuilt.');

        $this->getJson($this->url('migrate-fresh', ['confirm' => 'BORRAR-TODO']))
            ->assertOk()
            ->assertJsonPath('accion', 'migrate-fresh')
            ->assertJsonPath('aviso', fn ($a) => str_contains($a, 'VACÍA'));
    }

    /** Sin la frase exacta, la orden ni siquiera se emite. */
    public function test_sin_confirmar_no_se_emite_ninguna_orden(): void
    {
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')->never();

        $this->getJson($this->url('migrate-fresh', ['confirm' => 'quizas']))->assertStatus(428);
    }

    /** Las dos listas de acciones no pueden separarse: un 404 mudo cuesta caro. */
    public function test_la_ruta_conoce_las_acciones_nuevas(): void
    {
        preg_match("/->where\('action', '([^']+)'\)/", file_get_contents(base_path('routes/deploy.php')), $m);
        $enLaRuta = explode('|', $m[1] ?? '');

        $this->assertContains('db-status', $enLaRuta);
        $this->assertContains('migrate-fresh', $enLaRuta);
    }
}
