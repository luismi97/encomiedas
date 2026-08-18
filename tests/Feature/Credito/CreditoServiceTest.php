<?php

namespace Tests\Feature\Credito;

use App\Models\Branch;
use App\Models\CreditStatement;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CreditoServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sede;
    private Customer $cliente;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);

        $this->cliente = Customer::create([
            'name' => 'Ferretería El Roble S.A.',
            'identification' => '3101778899', 'identification_type' => '02',
            'payment_condition' => Customer::PAYMENT_CREDIT,
            'credit_limit' => 500000, 'credit_cutoff_day' => 30,
        ]);

        $this->usuario = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function servicio(): CreditoService
    {
        return app(CreditoService::class);
    }

    private function guiaCredito(float $total, ?Customer $cliente = null): Invoice
    {
        return Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sede->id,
            'delivery_branch_id' => $this->sede->id,
            'sender_customer_id' => ($cliente ?? $this->cliente)->id,
            'sender_name' => 'Ferretería', 'recipient_name' => 'Destinatario',
            'subtotal' => $total, 'discount_amount' => 0, 'tax_total' => 0, 'total' => $total,
            'sale_condition' => Invoice::SALE_CREDIT,
            'created_by' => $this->usuario->id,
        ])->fresh();
    }

    // ── Acumulación ───────────────────────────────────────────────────

    public function test_las_guias_a_credito_se_acumulan_sin_cortar(): void
    {
        $this->guiaCredito(50000);
        $this->guiaCredito(30000);

        $this->assertSame(80000.0, $this->servicio()->saldoSinCortar($this->cliente));
        $this->assertSame(80000.0, $this->servicio()->saldoTotal($this->cliente));
    }

    public function test_el_disponible_descuenta_lo_acumulado(): void
    {
        $this->guiaCredito(120000);

        // 500 000 de límite menos 120 000 acumulados.
        $this->assertSame(380000.0, $this->servicio()->disponible($this->cliente));
    }

    /**
     * La deuda son dos cosas sumadas: lo cortado sin pagar más lo acumulado.
     * Contar solo una es cómo un cliente se pasa del límite sin que nadie note.
     */
    public function test_el_saldo_suma_lo_cortado_y_lo_acumulado(): void
    {
        $this->guiaCredito(100000);
        $this->servicio()->cortar($this->cliente, $this->usuario);

        $this->guiaCredito(40000);

        $this->assertSame(100000.0, $this->servicio()->saldoFacturado($this->cliente));
        $this->assertSame(40000.0, $this->servicio()->saldoSinCortar($this->cliente));
        $this->assertSame(140000.0, $this->servicio()->saldoTotal($this->cliente));
    }

    // ── Límite de crédito ─────────────────────────────────────────────

    public function test_dentro_del_limite_no_hay_bloqueo(): void
    {
        $this->guiaCredito(100000);

        $this->assertNull($this->servicio()->bloqueoPorLimite($this->cliente, 50000));
    }

    public function test_pasarse_del_limite_explica_cuanto_queda(): void
    {
        $this->guiaCredito(480000);

        $motivo = $this->servicio()->bloqueoPorLimite($this->cliente, 50000);

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('Le quedan ₡20,000.00', $motivo);
    }

    public function test_un_cliente_de_contado_no_acumula_credito(): void
    {
        $contado = Customer::create(['name' => 'De mostrador', 'payment_condition' => Customer::PAYMENT_CASH]);

        $this->assertStringContainsString('es de contado', $this->servicio()->bloqueoPorLimite($contado, 1000));
    }

    public function test_sin_limite_configurado_no_se_bloquea(): void
    {
        $this->cliente->update(['credit_limit' => 0]);
        $this->guiaCredito(999999);

        $this->assertNull($this->servicio()->bloqueoPorLimite($this->cliente, 50000));
    }

    // ── Corte ─────────────────────────────────────────────────────────

    public function test_el_corte_agrupa_las_guias_del_periodo(): void
    {
        $this->guiaCredito(50000);
        $this->guiaCredito(30000);

        $estado = $this->servicio()->cortar($this->cliente, $this->usuario, null, 30);

        $this->assertSame('EC-000001', $estado->code);
        $this->assertSame('80000.00', (string) $estado->total);
        $this->assertSame('80000.00', (string) $estado->balance);
        $this->assertSame(2, $estado->guides()->count());
        $this->assertSame(now()->addDays(30)->toDateString(), $estado->due_date->toDateString());
    }

    /** Ya cortada no vuelve a entrar en otro corte. */
    public function test_una_guia_cortada_no_entra_en_el_siguiente_corte(): void
    {
        $this->guiaCredito(50000);
        $this->servicio()->cortar($this->cliente, $this->usuario);

        $this->guiaCredito(20000);
        $segundo = $this->servicio()->cortar($this->cliente, $this->usuario);

        $this->assertSame('20000.00', (string) $segundo->total);
        $this->assertSame(1, $segundo->guides()->count());
    }

    /** Sin nada que cortar no se crea un estado en cero que después haya que depurar. */
    public function test_sin_guias_pendientes_no_se_emite_estado(): void
    {
        $this->assertNull($this->servicio()->cortar($this->cliente, $this->usuario));
    }

    public function test_no_se_corta_a_un_cliente_de_contado(): void
    {
        $contado = Customer::create(['name' => 'De mostrador', 'payment_condition' => Customer::PAYMENT_CASH]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no es cliente de crédito');

        $this->servicio()->cortar($contado, $this->usuario);
    }

    public function test_una_guia_anulada_no_entra_al_corte(): void
    {
        $this->guiaCredito(50000);
        $anulada = $this->guiaCredito(30000);
        $anulada->forceFill(['status' => Invoice::STATUS_CANCELLED])->save();

        $estado = $this->servicio()->cortar($this->cliente, $this->usuario);

        $this->assertSame('50000.00', (string) $estado->total);
    }

    // ── Abonos ────────────────────────────────────────────────────────

    public function test_un_abono_baja_el_saldo_del_estado(): void
    {
        $this->guiaCredito(100000);
        $estado = $this->servicio()->cortar($this->cliente, $this->usuario);

        $this->servicio()->abonar($this->cliente, 40000, $this->usuario, $estado);

        $estado->refresh();
        $this->assertSame('40000.00', (string) $estado->paid);
        $this->assertSame('60000.00', (string) $estado->balance);
        $this->assertFalse($estado->estaSaldado());
    }

    public function test_pagar_todo_deja_el_estado_saldado(): void
    {
        $this->guiaCredito(100000);
        $estado = $this->servicio()->cortar($this->cliente, $this->usuario);

        $this->servicio()->abonar($this->cliente, 100000, $this->usuario, $estado);

        $this->assertTrue($estado->fresh()->estaSaldado());
        $this->assertSame(0.0, $this->servicio()->saldoFacturado($this->cliente));
    }

    /** Sin estado indicado, el abono se aplica del más viejo al más nuevo. */
    public function test_el_abono_sin_destino_paga_primero_lo_mas_viejo(): void
    {
        $this->guiaCredito(50000);
        $viejo = $this->servicio()->cortar($this->cliente, $this->usuario);

        $this->guiaCredito(80000);
        $nuevo = $this->servicio()->cortar($this->cliente, $this->usuario);
        $nuevo->update(['period_end' => now()->addDay()->toDateString()]);

        $this->servicio()->abonar($this->cliente, 70000, $this->usuario);

        $this->assertTrue($viejo->fresh()->estaSaldado());
        $this->assertSame('60000.00', (string) $nuevo->fresh()->balance);
    }

    /** Un abono de más deja el saldo en cero, no en negativo. */
    public function test_un_abono_excedido_no_deja_saldo_negativo(): void
    {
        $this->guiaCredito(50000);
        $estado = $this->servicio()->cortar($this->cliente, $this->usuario);

        $this->servicio()->abonar($this->cliente, 80000, $this->usuario, $estado);

        $this->assertSame('0.00', (string) $estado->fresh()->balance);
        $this->assertTrue($estado->fresh()->estaSaldado());
    }

    public function test_no_se_abona_cero_ni_negativo(): void
    {
        $this->expectExceptionMessage('mayor que cero');

        $this->servicio()->abonar($this->cliente, 0, $this->usuario);
    }

    // ── Antigüedad de saldos ──────────────────────────────────────────

    public function test_la_antiguedad_agrupa_por_tramo(): void
    {
        $this->guiaCredito(50000);
        $alDia = $this->servicio()->cortar($this->cliente, $this->usuario);

        $this->guiaCredito(30000);
        $vencido = $this->servicio()->cortar($this->cliente, $this->usuario);
        $vencido->update(['due_date' => now()->subDays(45)->toDateString()]);

        $antiguedad = $this->servicio()->antiguedadDeSaldos();

        $this->assertSame(50000.0, $antiguedad['Al día']['total']);
        $this->assertSame(30000.0, $antiguedad['31 – 60 días']['total']);
        $this->assertSame('31 – 60 días', $vencido->fresh()->tramoAntiguedad());
    }

    public function test_un_estado_saldado_sale_de_las_cuentas_por_cobrar(): void
    {
        $this->guiaCredito(50000);
        $estado = $this->servicio()->cortar($this->cliente, $this->usuario);
        $estado->update(['due_date' => now()->subDays(100)->toDateString()]);

        $this->servicio()->abonar($this->cliente, 50000, $this->usuario, $estado);

        $this->assertSame(0, CreditStatement::pending()->count());
        $this->assertSame(0, $estado->fresh()->diasVencido());
    }

    // ── Fecha de corte ────────────────────────────────────────────────

    public function test_le_toca_corte_el_dia_configurado(): void
    {
        $this->assertTrue($this->servicio()->leTocaCorte($this->cliente, now()->startOfMonth()->addDays(29)));
        $this->assertFalse($this->servicio()->leTocaCorte($this->cliente, now()->startOfMonth()));
    }

    /** Un corte el 31 en un mes de 30 cae el último día, o no se cortaría nunca. */
    public function test_un_corte_el_31_cae_el_ultimo_dia_del_mes_corto(): void
    {
        $this->cliente->update(['credit_cutoff_day' => 31]);

        $this->assertTrue($this->servicio()->leTocaCorte($this->cliente, \Carbon\Carbon::parse('2026-02-28')));
        $this->assertTrue($this->servicio()->leTocaCorte($this->cliente, \Carbon\Carbon::parse('2026-01-31')));
        $this->assertFalse($this->servicio()->leTocaCorte($this->cliente, \Carbon\Carbon::parse('2026-01-30')));
    }
}
