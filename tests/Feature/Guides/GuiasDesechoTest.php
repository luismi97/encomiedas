<?php

namespace Tests\Feature\Guides;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuiasDesechoTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        $this->usuario = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function guiaEnDestino(int $diasAtras): Invoice
    {
        $guia = Invoice::create([
            'status' => Invoice::STATUS_AT_DESTINATION,
            'pickup_branch_id' => $this->sj->id,
            'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 130, 'total' => 1130,
            'created_by' => $this->usuario->id,
        ]);

        $guia->forceFill(['arrived_at' => now()->subDays($diasAtras)])->save();

        return $guia->fresh();
    }

    public function test_avisa_las_que_pasaron_el_plazo_en_destino(): void
    {
        config(['encomiendas.disposal.warn_after_days' => 30]);

        $vencida = $this->guiaEnDestino(31);
        $reciente = $this->guiaEnDestino(5);

        $this->artisan('guias:desecho')
            ->expectsOutputToContain('Próximas a desecho: 1')
            ->assertSuccessful();

        $this->assertSame(Invoice::STATUS_NEAR_DISPOSAL, $vencida->fresh()->status);
        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $reciente->fresh()->status);
        $this->assertNotNull($vencida->fresh()->disposal_warned_at);
    }

    /** El aviso queda en la bitácora como automático, no a nombre de nadie. */
    public function test_el_aviso_queda_en_la_bitacora_como_automatico(): void
    {
        config(['encomiendas.disposal.warn_after_days' => 30]);
        $guia = $this->guiaEnDestino(40);

        $this->artisan('guias:desecho')->assertSuccessful();

        $ultimo = $guia->fresh()->statusHistories()->latest('id')->first();

        $this->assertSame(Invoice::STATUS_NEAR_DISPOSAL, $ultimo->to_status);
        $this->assertSame('Automático', $ultimo->sourceLabel());
        $this->assertNull($ultimo->user_id);
        $this->assertStringContainsString('Sin retirar', $ultimo->note);
    }

    /**
     * El requisito pide que el desecho quede autorizado por alguien con
     * permiso: apagado, el comando avisa pero no desecha.
     */
    public function test_sin_autorizacion_automatica_solo_reporta(): void
    {
        config([
            'encomiendas.disposal.warn_after_days' => 30,
            'encomiendas.disposal.dispose_after_days' => 15,
            'encomiendas.disposal.auto_dispose' => false,
        ]);

        $guia = $this->guiaEnDestino(60);
        $guia->forceFill([
            'status' => Invoice::STATUS_NEAR_DISPOSAL,
            'disposal_warned_at' => now()->subDays(20),
        ])->save();

        $this->artisan('guias:desecho')
            ->expectsOutputToContain('requieren autorización manual')
            ->assertSuccessful();

        $this->assertSame(Invoice::STATUS_NEAR_DISPOSAL, $guia->fresh()->status);
    }

    public function test_con_autorizacion_automatica_si_desecha(): void
    {
        config([
            'encomiendas.disposal.warn_after_days' => 30,
            'encomiendas.disposal.dispose_after_days' => 15,
            'encomiendas.disposal.auto_dispose' => true,
        ]);

        $guia = $this->guiaEnDestino(60);
        $guia->forceFill([
            'status' => Invoice::STATUS_NEAR_DISPOSAL,
            'disposal_warned_at' => now()->subDays(20),
        ])->save();

        $this->artisan('guias:desecho')->assertSuccessful();

        $guia->refresh();
        $this->assertSame(Invoice::STATUS_DISPOSED, $guia->status);
        $this->assertNotNull($guia->disposed_at);
    }

    public function test_dry_run_no_toca_nada(): void
    {
        config(['encomiendas.disposal.warn_after_days' => 30]);
        $guia = $this->guiaEnDestino(40);

        $this->artisan('guias:desecho', ['--dry-run' => true])
            ->expectsOutputToContain('Próximas a desecho: 1')
            ->assertSuccessful();

        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $guia->fresh()->status);
    }

    /** Una guía entregada ya salió del ciclo: el cron no debe tocarla. */
    public function test_no_toca_las_guias_ya_entregadas(): void
    {
        config(['encomiendas.disposal.warn_after_days' => 30]);

        $guia = $this->guiaEnDestino(90);
        $guia->forceFill(['status' => Invoice::STATUS_DELIVERED])->save();

        $this->artisan('guias:desecho')
            ->expectsOutputToContain('Próximas a desecho: 0')
            ->assertSuccessful();

        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->fresh()->status);
    }
}
