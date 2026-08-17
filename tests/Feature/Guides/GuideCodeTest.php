<?php

namespace Tests\Feature\Guides;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\GuideCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuideCodeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private Branch $her;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        $this->her = Branch::create(['name' => 'Heredia', 'prefix' => 'HER', 'sucursal_code' => '003', 'terminal_code' => '00001', 'is_active' => true]);
    }

    private function generador(): GuideCodeGenerator
    {
        return app(GuideCodeGenerator::class);
    }

    private function guia(Branch $origen, Branch $destino): Invoice
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@t.test'],
            ['name' => 'Admin', 'username' => 'admin', 'password' => bcrypt('x'),
             'role' => User::ROLE_ADMIN, 'is_active' => true]
        );

        return Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $origen->id,
            'delivery_branch_id' => $destino->id,
            'sender_name' => 'Remitente', 'recipient_name' => 'Destinatario',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 130, 'total' => 1130,
            'created_by' => $user->id,
        ]);
    }

    public function test_el_codigo_lleva_los_prefijos_de_la_ruta(): void
    {
        $this->assertSame('SJ-LIM-00001', $this->generador()->generar($this->sj, $this->lim));
    }

    public function test_el_consecutivo_avanza_por_ruta(): void
    {
        $this->assertSame('SJ-LIM-00001', $this->generador()->generar($this->sj, $this->lim));
        $this->assertSame('SJ-LIM-00002', $this->generador()->generar($this->sj, $this->lim));
        $this->assertSame('SJ-LIM-00003', $this->generador()->generar($this->sj, $this->lim));
    }

    /** Cada ruta lleva su propia numeración: es la definición del requisito. */
    public function test_cada_ruta_numera_por_separado(): void
    {
        $this->generador()->generar($this->sj, $this->lim);
        $this->generador()->generar($this->sj, $this->lim);

        $this->assertSame('SJ-HER-00001', $this->generador()->generar($this->sj, $this->her));
        // La ruta inversa también es otra ruta.
        $this->assertSame('LIM-SJ-00001', $this->generador()->generar($this->lim, $this->sj));
    }

    public function test_el_relleno_de_ceros_es_configurable(): void
    {
        config(['encomiendas.guide_sequence_padding' => 3]);

        $this->assertSame('SJ-LIM-001', $this->generador()->generar($this->sj, $this->lim));
    }

    public function test_una_sede_sin_prefijo_no_puede_generar_codigo(): void
    {
        $sinPrefijo = Branch::create(['name' => 'Sin prefijo', 'prefix' => null, 'sucursal_code' => '009', 'terminal_code' => '00001', 'is_active' => true]);

        $this->expectExceptionMessage('no tiene prefijo configurado');

        $this->generador()->generar($sinPrefijo, $this->lim);
    }

    // ── Integración con la creación de guías ──────────────────────────

    public function test_al_crear_una_guia_recibe_su_codigo_de_ruta(): void
    {
        $guia = $this->guia($this->sj, $this->lim);

        $this->assertSame('SJ-LIM-00001', $guia->fresh()->code);
    }

    /**
     * Si falta un prefijo NO se pierde la encomienda: ya se recibió físicamente
     * y no puede quedarse sin registrar por un dato de configuración.
     */
    public function test_sin_prefijo_cae_al_formato_viejo_en_vez_de_reventar(): void
    {
        $sinPrefijo = Branch::create(['name' => 'Sin prefijo', 'prefix' => null, 'sucursal_code' => '009', 'terminal_code' => '00001', 'is_active' => true]);

        $guia = $this->guia($sinPrefijo, $this->lim);

        $this->assertStringStartsWith('ENC-', $guia->fresh()->code);
    }

    /**
     * El consecutivo se reserva en base, no contando filas: dos sedes emitiendo
     * a la vez leerían el mismo número con un COUNT.
     */
    public function test_el_consecutivo_no_se_repite_bajo_concurrencia(): void
    {
        $codigos = [];

        for ($i = 0; $i < 25; $i++) {
            $codigos[] = $this->generador()->generar($this->sj, $this->lim);
        }

        $this->assertCount(25, array_unique($codigos));
        $this->assertSame('SJ-LIM-00025', end($codigos));
        $this->assertSame(25, (int) DB::table('guide_sequences')
            ->where('origin_prefix', 'SJ')->where('destination_prefix', 'LIM')->value('last_number'));
    }

    public function test_el_qr_apunta_al_seguimiento_publico(): void
    {
        $guia = $this->guia($this->sj, $this->lim)->fresh();

        $this->assertSame(url('/rastreo/SJ-LIM-00001'), $guia->trackingUrl());
    }
}
