<?php

namespace Tests\Feature\Deploy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Reconciliación de la tabla `migrations` con el esquema real.
 *
 * Laravel decide qué correr comparando NOMBRES DE ARCHIVO contra esa tabla;
 * nunca mira el esquema. Un archivo sobrante de una instalación anterior —las
 * migraciones por defecto de Laravel 10, que la 11 consolidó en 0001_01_01_*—
 * aparece como pendiente, se ejecuta, choca con la tabla que ya existe y
 * `migrate` deja de avanzar para siempre.
 */
class ReparacionDeMigracionesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'un-token-de-al-menos-treinta-y-dos-caracteres';

    /** @var array<int,string> */
    private array $archivosCreados = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::store('file')->flush();
        config(['app.deploy_token' => self::TOKEN]);
    }

    protected function tearDown(): void
    {
        foreach ($this->archivosCreados as $ruta) {
            File::delete($ruta);
        }

        parent::tearDown();
    }

    private function url(string $accion, array $params = []): string
    {
        return "/__deploy/{$accion}?" . http_build_query(['token' => self::TOKEN] + $params);
    }

    /** Deja en disco un archivo de migración huérfano, como el del servidor. */
    private function archivoHuerfano(string $nombre, string $tabla): string
    {
        $ruta = database_path("migrations/{$nombre}.php");

        File::put($ruta, <<<PHP
        <?php
        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;
        return new class extends Migration {
            public function up(): void {
                Schema::create('{$tabla}', function (Blueprint \$t) { \$t->id(); });
            }
            public function down(): void { Schema::dropIfExists('{$tabla}'); }
        };
        PHP);

        $this->archivosCreados[] = $ruta;

        return $nombre;
    }

    // ── El diagnóstico ────────────────────────────────────────────────

    public function test_sin_token_no_existe(): void
    {
        $this->get('/__deploy/migrate-repair')->assertNotFound();
    }

    public function test_sin_pendientes_no_hay_nada_que_hacer(): void
    {
        $this->getJson($this->url('migrate-repair'))
            ->assertOk()
            ->assertJsonPath('reparado', false)
            ->assertJsonPath('mensaje', fn ($m) => str_contains($m, 'nada que reconciliar'));
    }

    /** Primero informa y no toca nada: hay que ver la lista antes. */
    public function test_primero_muestra_lo_que_haria(): void
    {
        $huerfano = $this->archivoHuerfano('2014_10_12_000000_create_users_table', 'users');

        $r = $this->getJson($this->url('migrate-repair'))->assertStatus(428);

        $r->assertJsonPath('reparado', false);
        $this->assertSame([$huerfano], collect($r->json('se_marcarian_como_aplicadas'))->pluck('migracion')->all());
        $this->assertFalse(DB::table('migrations')->where('migration', $huerfano)->exists(),
            'Sin confirmar no puede escribir nada.');
    }

    // ── La reparación ─────────────────────────────────────────────────

    /** El caso real: cuatro archivos de Laravel 10 sobre un esquema completo. */
    public function test_registra_las_migraciones_cuyas_tablas_ya_existen(): void
    {
        $huerfanos = [
            $this->archivoHuerfano('2014_10_12_000000_create_users_table', 'users'),
            $this->archivoHuerfano('2014_10_12_100000_create_password_reset_tokens_table', 'password_reset_tokens'),
            $this->archivoHuerfano('2019_08_19_000000_create_failed_jobs_table', 'failed_jobs'),
        ];

        $r = $this->getJson($this->url('migrate-repair', ['confirm' => 1]))->assertOk();

        $this->assertSame($huerfanos, $r->json('marcadas'));

        foreach ($huerfanos as $h) {
            $this->assertTrue(DB::table('migrations')->where('migration', $h)->exists());
        }
    }

    /** Y después `migrate` ya no choca: es la prueba de que quedó reparado. */
    public function test_tras_reparar_migrate_deja_de_fallar(): void
    {
        $this->archivoHuerfano('2014_10_12_000000_create_users_table', 'users');

        $this->getJson($this->url('migrate-repair', ['confirm' => 1]))->assertOk();

        $this->artisan('migrate', ['--force' => true])
            ->expectsOutputToContain('Nothing to migrate')
            ->assertSuccessful();
    }

    /** Una migración que crea algo que NO está se deja correr: no se falsea. */
    public function test_no_marca_lo_que_de_verdad_falta(): void
    {
        $falta = $this->archivoHuerfano('2030_01_01_000000_create_bodegas_table', 'bodegas');

        $r = $this->getJson($this->url('migrate-repair'))->assertStatus(428);

        $this->assertSame([], $r->json('se_marcarian_como_aplicadas'));
        $this->assertSame([$falta], collect($r->json('se_dejarian_para_correr'))->pluck('migracion')->all());
    }

    public function test_separa_las_que_faltan_de_las_que_sobran(): void
    {
        $sobra = $this->archivoHuerfano('2014_10_12_000000_create_users_table', 'users');
        $falta = $this->archivoHuerfano('2030_01_01_000000_create_bodegas_table', 'bodegas');

        $r = $this->getJson($this->url('migrate-repair', ['confirm' => 1]))->assertOk();

        $this->assertSame([$sobra], $r->json('marcadas'));
        $this->assertSame([$falta], $r->json('pendientes_reales'));

        // La que falta de verdad sigue pendiente y corre normalmente.
        $this->assertFalse(DB::table('migrations')->where('migration', $falta)->exists());
    }

    /** No borra archivos: la basura se limpia a mano, con criterio. */
    public function test_no_borra_ningun_archivo(): void
    {
        $this->archivoHuerfano('2014_10_12_000000_create_users_table', 'users');

        $this->getJson($this->url('migrate-repair', ['confirm' => 1]))->assertOk();

        $this->assertFileExists(database_path('migrations/2014_10_12_000000_create_users_table.php'));
    }

    /** Correrla dos veces no duplica filas ni rompe nada. */
    public function test_repararlo_dos_veces_es_inofensivo(): void
    {
        $h = $this->archivoHuerfano('2014_10_12_000000_create_users_table', 'users');

        $this->getJson($this->url('migrate-repair', ['confirm' => 1]))->assertOk();
        $this->getJson($this->url('migrate-repair', ['confirm' => 1]))
            ->assertOk()
            ->assertJsonPath('reparado', false);

        $this->assertSame(1, DB::table('migrations')->where('migration', $h)->count());
    }
}
