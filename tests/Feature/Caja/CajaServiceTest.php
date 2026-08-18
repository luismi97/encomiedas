<?php

namespace Tests\Feature\Caja;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Denomination;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CajaServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sede;
    private CashRegister $caja;
    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        // La sede nace con su «Caja principal»; crear otra dejaría dos en la
        // misma sede y las pruebas de turno único dejarían de probar nada.
        $this->caja = $this->sede->cashRegisters()->firstOrFail();

        $this->cajero = User::create([
            'name' => 'Yolanda Campos', 'username' => 'cajera', 'email' => 'cajera@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
            'branch_id' => $this->sede->id,
        ]);

        // Las denominaciones ya vienen con el esquema: firstOrCreate para no
        // chocar con el índice único de `value`.
        foreach ([20000, 10000, 5000, 2000, 1000, 500, 100, 50, 25] as $orden => $valor) {
            Denomination::firstOrCreate(
                ['value' => $valor],
                ['sort_order' => $orden, 'is_active' => true]
            );
        }
    }

    private function servicio(): CajaService
    {
        return app(CajaService::class);
    }

    private function guia(float $total, string $medio = 'cash'): Invoice
    {
        return Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sede->id,
            'delivery_branch_id' => $this->sede->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => $total, 'discount_amount' => 0, 'tax_total' => 0, 'total' => $total,
            'payment_method' => $medio,
            'created_by' => $this->cajero->id,
        ])->fresh();
    }

    private function denominacion(int $valor): Denomination
    {
        return Denomination::where('value', $valor)->firstOrFail();
    }

    // ── Apertura ──────────────────────────────────────────────────────

    public function test_se_abre_un_turno_con_su_fondo_inicial(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);

        $this->assertTrue($sesion->estaAbierta());
        $this->assertSame('50000.00', (string) $sesion->opening_float);
        $this->assertSame($this->cajero->id, $sesion->opened_by);
    }

    /** Dos turnos abiertos harían que el segundo arrastre los cobros del primero. */
    public function test_una_caja_no_puede_tener_dos_turnos_abiertos(): void
    {
        $this->servicio()->abrir($this->caja, $this->cajero, 50000);

        $this->expectExceptionMessage('ya tiene un turno abierto');

        $this->servicio()->abrir($this->caja, $this->cajero, 30000);
    }

    public function test_el_fondo_inicial_no_puede_ser_negativo(): void
    {
        $this->expectExceptionMessage('no puede ser negativo');

        $this->servicio()->abrir($this->caja, $this->cajero, -100);
    }

    // ── Cobros ────────────────────────────────────────────────────────

    public function test_un_cobro_entra_al_turno_abierto(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);
        $guia = $this->guia(5000);

        $movimiento = $this->servicio()->registrarCobro($guia, $this->cajero);

        $this->assertNotNull($movimiento);
        $this->assertSame(CashMovement::TYPE_SALE, $movimiento->type);
        $this->assertSame('5000.00', (string) $movimiento->amount);
        $this->assertSame($guia->code, $movimiento->reference);
        $this->assertSame(1, $sesion->fresh()->movements()->count());
    }

    /** Sin caja abierta el cobro no se registra, y quien llama se entera. */
    public function test_sin_caja_abierta_no_hay_cobro_registrado(): void
    {
        $this->assertNull($this->servicio()->registrarCobro($this->guia(5000), $this->cajero));
    }

    /** Reabrir y guardar una guía no puede duplicar su cobro. */
    public function test_registrar_el_mismo_cobro_dos_veces_no_lo_duplica(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);
        $guia = $this->guia(5000);

        $this->servicio()->registrarCobro($guia, $this->cajero);
        $this->servicio()->registrarCobro($guia, $this->cajero);

        $this->assertSame(1, $sesion->fresh()->movements()->count());
    }

    // ── Efectivo esperado ─────────────────────────────────────────────

    public function test_el_esperado_suma_fondo_y_cobros_en_efectivo(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);

        $this->servicio()->registrarCobro($this->guia(5000), $this->cajero);
        $this->servicio()->registrarCobro($this->guia(3000), $this->cajero);

        $this->assertSame(58000.0, $this->servicio()->efectivoEsperado($sesion->fresh()));
    }

    /** Una tarjeta no cambia lo que hay en la gaveta. */
    public function test_los_cobros_que_no_son_efectivo_no_afectan_la_gaveta(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);

        $this->servicio()->registrarCobro($this->guia(5000, 'cash'), $this->cajero);
        $this->servicio()->registrarCobro($this->guia(9000, 'card'), $this->cajero);
        $this->servicio()->registrarCobro($this->guia(7000, 'sinpe'), $this->cajero);

        $this->assertSame(55000.0, $this->servicio()->efectivoEsperado($sesion->fresh()));
    }

    public function test_las_entradas_y_salidas_mueven_el_esperado(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);

        $this->servicio()->registrarMovimiento($sesion, CashMovement::TYPE_IN, 10000, 'Reposición de sencillo', $this->cajero);
        $this->servicio()->registrarMovimiento($sesion, CashMovement::TYPE_OUT, 4000, 'Pago de mensajería', $this->cajero);

        $this->assertSame(56000.0, $this->servicio()->efectivoEsperado($sesion->fresh()));
    }

    public function test_toda_salida_de_efectivo_necesita_motivo(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);

        $this->expectExceptionMessage('necesita un motivo');

        $this->servicio()->registrarMovimiento($sesion, CashMovement::TYPE_OUT, 4000, '   ', $this->cajero);
    }

    // ── Arqueo ────────────────────────────────────────────────────────

    public function test_el_arqueo_cuadrado_no_deja_diferencia(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);
        $this->servicio()->registrarCobro($this->guia(5000), $this->cajero);

        // 55 000 exactos: 2×20 000 + 1×10 000 + 1×5 000
        $sesion = $this->servicio()->cerrar($sesion->fresh(), $this->cajero, [
            $this->denominacion(20000)->id => 2,
            $this->denominacion(10000)->id => 1,
            $this->denominacion(5000)->id  => 1,
        ]);

        $this->assertSame(CashSession::STATUS_CLOSED, $sesion->status);
        $this->assertSame('55000.00', (string) $sesion->expected_cash);
        $this->assertSame('55000.00', (string) $sesion->counted_cash);
        $this->assertTrue($sesion->cuadra());
    }

    public function test_un_faltante_queda_en_negativo(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);
        $this->servicio()->registrarCobro($this->guia(5000), $this->cajero);

        // Contaron 53 000 pero deberían ser 55 000.
        $sesion = $this->servicio()->cerrar($sesion->fresh(), $this->cajero, [
            $this->denominacion(20000)->id => 2,
            $this->denominacion(10000)->id => 1,
            $this->denominacion(2000)->id  => 1,
            $this->denominacion(1000)->id  => 1,
        ]);

        $this->assertSame('-2000.00', (string) $sesion->discrepancy);
        $this->assertTrue($sesion->hayFaltante());
        $this->assertFalse($sesion->haySobrante());
    }

    public function test_un_sobrante_queda_en_positivo(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);

        $sesion = $this->servicio()->cerrar($sesion->fresh(), $this->cajero, [
            $this->denominacion(20000)->id => 2,
            $this->denominacion(10000)->id => 1,
            $this->denominacion(1000)->id  => 1,
        ]);

        $this->assertSame('1000.00', (string) $sesion->discrepancy);
        $this->assertTrue($sesion->haySobrante());
    }

    public function test_el_arqueo_guarda_el_conteo_por_denominacion(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 25000);

        $sesion = $this->servicio()->cerrar($sesion->fresh(), $this->cajero, [
            $this->denominacion(20000)->id => 1,
            $this->denominacion(5000)->id  => 1,
        ]);

        $conteo = $sesion->counts()->with('denomination')->get();

        $this->assertSame(2, $conteo->count());
        $this->assertSame('20000.00', (string) $conteo->firstWhere('denomination.value', 20000)->subtotal);
    }

    public function test_un_turno_cerrado_no_admite_mas_movimientos(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);
        $sesion = $this->servicio()->cerrar($sesion->fresh(), $this->cajero, []);

        $this->expectExceptionMessage('ya está cerrado');

        $this->servicio()->registrarMovimiento($sesion, CashMovement::TYPE_IN, 1000, 'Tarde', $this->cajero);
    }

    public function test_no_se_cierra_dos_veces_el_mismo_turno(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);
        $sesion = $this->servicio()->cerrar($sesion->fresh(), $this->cajero, []);

        $this->expectExceptionMessage('ya fue cerrado');

        $this->servicio()->cerrar($sesion, $this->cajero, []);
    }

    /** El cierre desglosa por medio de pago: efectivo, tarjeta, SINPE. */
    public function test_el_cierre_desglosa_por_medio_de_pago(): void
    {
        $sesion = $this->servicio()->abrir($this->caja, $this->cajero, 50000);

        $this->servicio()->registrarCobro($this->guia(5000, 'cash'), $this->cajero);
        $this->servicio()->registrarCobro($this->guia(3000, 'cash'), $this->cajero);
        $this->servicio()->registrarCobro($this->guia(9000, 'card'), $this->cajero);

        $totales = $this->servicio()->totalesPorMedio($sesion->fresh());

        $this->assertSame(8000.0, $totales['cash']['total']);
        $this->assertSame(2, $totales['cash']['cantidad']);
        $this->assertSame(9000.0, $totales['card']['total']);
        $this->assertSame('Efectivo', $totales['cash']['etiqueta']);
    }

    public function test_cerrado_el_turno_se_puede_abrir_otro(): void
    {
        $primero = $this->servicio()->abrir($this->caja, $this->cajero, 50000);
        $this->servicio()->cerrar($primero->fresh(), $this->cajero, []);

        $segundo = $this->servicio()->abrir($this->caja, $this->cajero, 30000);

        $this->assertTrue($segundo->estaAbierta());
        $this->assertNotSame($primero->id, $segundo->id);
    }
}
