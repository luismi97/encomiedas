<?php

namespace Tests\Feature\Tarifario;

use App\Models\Branch;
use App\Models\Rate;
use App\Services\Tarifario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TarifarioTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sanJose;
    private Branch $limon;
    private Branch $heredia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanJose = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->limon   = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        $this->heredia = Branch::create(['name' => 'Heredia', 'prefix' => 'HER', 'sucursal_code' => '003', 'terminal_code' => '00001', 'is_active' => true]);
    }

    private function tarifario(): Tarifario
    {
        return app(Tarifario::class);
    }

    // ── Peso volumétrico ──────────────────────────────────────────────

    public function test_el_peso_volumetrico_sale_de_las_dimensiones(): void
    {
        // 40 × 30 × 25 = 30 000 cm³ ÷ 5000 = 6 kg
        $this->assertSame(6.0, $this->tarifario()->pesoVolumetrico(40, 30, 25));
    }

    public function test_sin_dimensiones_completas_no_hay_volumetrico(): void
    {
        $this->assertSame(0.0, $this->tarifario()->pesoVolumetrico(40, 30, null));
        $this->assertSame(0.0, $this->tarifario()->pesoVolumetrico(null, null, null));
    }

    /** Un paquete grande y liviano ocupa camión igual que uno pesado. */
    public function test_se_cobra_por_el_mayor_entre_real_y_volumetrico(): void
    {
        // Caja grande de 2 kg reales pero 6 kg volumétricos.
        $this->assertSame(6.0, $this->tarifario()->pesoFacturable(2, 40, 30, 25));

        // Paquete pequeño y pesado: manda el real.
        $this->assertSame(12.0, $this->tarifario()->pesoFacturable(12, 10, 10, 10));
    }

    public function test_el_divisor_volumetrico_es_configurable(): void
    {
        config(['encomiendas.volumetric_divisor' => 6000]);

        // 30 000 ÷ 6000 = 5 kg
        $this->assertSame(5.0, $this->tarifario()->pesoVolumetrico(40, 30, 25));
    }

    // ── Selección de tarifa ───────────────────────────────────────────

    public function test_encuentra_la_tarifa_del_rango_de_peso(): void
    {
        Rate::create(['name' => 'Liviana', 'min_weight' => 0, 'max_weight' => 5, 'price' => 2000]);
        Rate::create(['name' => 'Media', 'min_weight' => 5, 'max_weight' => 20, 'price' => 4500]);

        $tarifa = $this->tarifario()->buscar($this->sanJose, $this->limon, 8);

        $this->assertSame('Media', $tarifa->name);
        $this->assertSame(4500.0, $tarifa->precioPara(8));
    }

    /**
     * El extremo superior es exclusivo: con rangos 0–5 y 5–20, cinco kilos
     * exactos caen en el segundo y no en los dos.
     */
    public function test_el_limite_del_rango_no_se_traslapa(): void
    {
        Rate::create(['name' => 'Liviana', 'min_weight' => 0, 'max_weight' => 5, 'price' => 2000]);
        Rate::create(['name' => 'Media', 'min_weight' => 5, 'max_weight' => 20, 'price' => 4500]);

        $this->assertSame('Liviana', $this->tarifario()->buscar($this->sanJose, $this->limon, 4.99)->name);
        $this->assertSame('Media', $this->tarifario()->buscar($this->sanJose, $this->limon, 5)->name);
    }

    /** Gana la que declara más condiciones, no la primera que aparezca. */
    public function test_la_tarifa_de_ruta_especifica_le_gana_a_la_base(): void
    {
        Rate::create(['name' => 'Base nacional', 'min_weight' => 0, 'max_weight' => 10, 'price' => 3000]);
        Rate::create([
            'name' => 'SJ a Limón', 'min_weight' => 0, 'max_weight' => 10, 'price' => 5000,
            'origin_branch_id' => $this->sanJose->id, 'destination_branch_id' => $this->limon->id,
        ]);

        $this->assertSame('SJ a Limón', $this->tarifario()->buscar($this->sanJose, $this->limon, 3)->name);
        // Otra ruta cae en la base.
        $this->assertSame('Base nacional', $this->tarifario()->buscar($this->sanJose, $this->heredia, 3)->name);
    }

    public function test_el_tipo_de_envio_desempata(): void
    {
        Rate::create(['name' => 'General', 'min_weight' => 0, 'max_weight' => 5, 'price' => 3000]);
        Rate::create(['name' => 'Solo sobres', 'min_weight' => 0, 'max_weight' => 5, 'price' => 1200, 'shipment_type' => Rate::TYPE_ENVELOPE]);

        $this->assertSame('Solo sobres', $this->tarifario()->buscar($this->sanJose, $this->limon, 1, Rate::TYPE_ENVELOPE)->name);
        $this->assertSame('General', $this->tarifario()->buscar($this->sanJose, $this->limon, 1, Rate::TYPE_PACKAGE)->name);
    }

    public function test_una_tarifa_inactiva_no_se_usa(): void
    {
        Rate::create(['name' => 'Vieja', 'min_weight' => 0, 'max_weight' => 10, 'price' => 2000, 'is_active' => false]);

        $this->assertNull($this->tarifario()->buscar($this->sanJose, $this->limon, 3));
    }

    // ── Tramo abierto ─────────────────────────────────────────────────

    public function test_el_tramo_sin_tope_cobra_por_kilo_adicional(): void
    {
        Rate::create([
            'name' => 'Pesada', 'min_weight' => 20, 'max_weight' => null,
            'price' => 9000, 'price_per_extra_kg' => 500,
        ]);

        $tarifa = $this->tarifario()->buscar($this->sanJose, $this->limon, 25);

        // 9000 base + 5 kg sobre el mínimo × 500
        $this->assertSame(11500.0, $tarifa->precioPara(25));
    }

    public function test_los_kilos_adicionales_se_redondean_hacia_arriba(): void
    {
        Rate::create(['min_weight' => 20, 'max_weight' => null, 'price' => 9000, 'price_per_extra_kg' => 500]);

        // 20.1 kg: nadie cobra una décima de kilo.
        $this->assertSame(9500.0, $this->tarifario()->buscar($this->sanJose, $this->limon, 20.1)->precioPara(20.1));
    }

    // ── Cotización completa ───────────────────────────────────────────

    public function test_la_cotizacion_usa_el_peso_volumetrico_para_elegir_tarifa(): void
    {
        Rate::create(['name' => 'Liviana', 'min_weight' => 0, 'max_weight' => 5, 'price' => 2000]);
        Rate::create(['name' => 'Media', 'min_weight' => 5, 'max_weight' => 20, 'price' => 4500]);

        // 2 kg reales, pero 6 kg volumétricos: tiene que caer en «Media».
        $cotizacion = $this->tarifario()->cotizar($this->sanJose, $this->limon, 2, 40, 30, 25);

        $this->assertSame(2.0, $cotizacion['peso_real']);
        $this->assertSame(6.0, $cotizacion['peso_volumetrico']);
        $this->assertSame(6.0, $cotizacion['peso_facturable']);
        $this->assertSame('Media', $cotizacion['tarifa']->name);
        $this->assertSame(4500.0, $cotizacion['precio']);
    }

    /**
     * Sin tarifa se devuelve null y un motivo, no un cero: es preferible que el
     * cajero digite el precio y se entere, a cobrar cero en silencio.
     */
    public function test_sin_tarifa_aplicable_avisa_en_vez_de_cobrar_cero(): void
    {
        Rate::create(['name' => 'Liviana', 'min_weight' => 0, 'max_weight' => 5, 'price' => 2000]);

        $cotizacion = $this->tarifario()->cotizar($this->sanJose, $this->limon, 50);

        $this->assertNull($cotizacion['tarifa']);
        $this->assertNull($cotizacion['precio']);
        $this->assertStringContainsString('Digite el precio manualmente', $cotizacion['motivo']);
    }
}
