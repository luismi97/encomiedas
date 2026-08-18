<?php

namespace Tests\Feature\Operacion;

use App\Livewire\Chofer\ChoferPanel;
use App\Livewire\Invoices\InvoiceShow;
use App\Models\Branch;
use App\Models\Dispatch;
use App\Models\GuideIncident;
use App\Models\Holiday;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class IncidenciasHorariosChoferTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;
    private User $chofer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        $this->chofer = User::create([
            'name' => 'Randall Mora', 'username' => 'chofer', 'email' => 'chofer@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_REPARTIDOR, 'is_active' => true,
        ]);
    }

    private function guia(): Invoice
    {
        return Invoice::withoutGlobalScopes()->create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José Fernández',
            'subtotal' => 3000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 3000,
            'created_by' => $this->admin->id,
        ])->fresh();
    }

    // ── Incidencias ───────────────────────────────────────────────────

    /** Una incidencia NO cambia el estado: un ausente deja el paquete donde está. */
    public function test_una_incidencia_no_mueve_el_estado_de_la_guia(): void
    {
        $guia = $this->guia();

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('openIncidentForm')
            ->set('incidentType', GuideIncident::TYPE_ABSENT)
            ->set('incidentDescription', 'Se visitó a las 10:00 y no había nadie')
            ->call('registrarIncidencia');

        $guia->refresh();

        $this->assertSame(Invoice::STATUS_PENDING, $guia->status);
        $this->assertSame(1, $guia->incidents()->count());
        $this->assertTrue($guia->tieneIncidenciasAbiertas());
    }

    public function test_una_incidencia_sin_detalle_no_se_registra(): void
    {
        $guia = $this->guia();

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('openIncidentForm')
            ->set('incidentDescription', '   ')
            ->call('registrarIncidencia');

        $this->assertSame(0, $guia->incidents()->count());
    }

    public function test_una_incidencia_se_marca_resuelta(): void
    {
        $guia = $this->guia();
        $incidencia = GuideIncident::create([
            'invoice_id' => $guia->id, 'type' => GuideIncident::TYPE_DAMAGED,
            'description' => 'Caja golpeada', 'reported_by' => $this->admin->id, 'reported_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('resolverIncidencia', $incidencia->id);

        $incidencia->refresh();

        $this->assertTrue($incidencia->estaResuelta());
        $this->assertSame($this->admin->id, $incidencia->resolved_by);
        $this->assertFalse($guia->fresh()->tieneIncidenciasAbiertas());
    }

    // ── Horarios y feriados ───────────────────────────────────────────

    public function test_la_sede_esta_cerrada_fuera_de_horario(): void
    {
        $this->sj->update(['business_hours' => [
            1 => ['abre' => '08:00', 'cierra' => '17:00'],
        ]]);

        // Lunes 10:00 abierta, lunes 19:00 cerrada.
        $this->assertTrue($this->sj->estaAbierta(Carbon::parse('2026-08-17 10:00')));
        $this->assertFalse($this->sj->estaAbierta(Carbon::parse('2026-08-17 19:00')));
    }

    public function test_un_dia_sin_horario_esta_cerrado(): void
    {
        $this->sj->update(['business_hours' => [1 => ['abre' => '08:00', 'cierra' => '17:00']]]);

        // Domingo: no hay horario configurado.
        $this->assertFalse($this->sj->estaAbierta(Carbon::parse('2026-08-16 10:00')));
    }

    /** Un feriado cierra aunque el día tenga horario. */
    public function test_un_feriado_cierra_la_sede(): void
    {
        $this->sj->update(['business_hours' => [
            1 => ['abre' => '08:00', 'cierra' => '17:00'],
        ]]);

        Holiday::create(['date' => '2026-08-17', 'name' => 'Feriado de prueba']);

        $this->assertFalse($this->sj->estaAbierta(Carbon::parse('2026-08-17 10:00')));
    }

    public function test_dice_cuando_vuelve_a_abrir(): void
    {
        $this->sj->update(['business_hours' => [
            1 => ['abre' => '08:00', 'cierra' => '17:00'],
            2 => ['abre' => '08:00', 'cierra' => '17:00'],
        ]]);

        // Lunes a las 19:00 (cerrado) → abre el martes a las 08:00.
        $proxima = $this->sj->proximaApertura(Carbon::parse('2026-08-17 19:00'));

        $this->assertSame('2026-08-18 08:00', $proxima->format('Y-m-d H:i'));
    }

    public function test_sin_horario_configurado_no_inventa_una_fecha(): void
    {
        $this->assertNull($this->sj->proximaApertura(Carbon::parse('2026-08-17 19:00')));
    }

    // ── Vista del chofer ──────────────────────────────────────────────

    private function cierreAsignado(): Dispatch
    {
        Bus::fake();
        $servicio = app(DispatchService::class);

        $cierre = Dispatch::withoutGlobalScopes()->create([
            'code' => 'CIE-000001',
            'origin_branch_id' => $this->sj->id, 'destination_branch_id' => $this->lim->id,
            'driver_name' => 'Randall Mora', 'driver_user_id' => $this->chofer->id,
            'created_by' => $this->admin->id,
        ]);

        $servicio->agregarGuia($cierre, $this->guia());

        return $servicio->despachar($cierre->fresh(), $this->admin);
    }

    public function test_el_chofer_ve_solo_sus_cierres_asignados(): void
    {
        $mio = $this->cierreAsignado();

        Dispatch::withoutGlobalScopes()->create([
            'code' => 'CIE-000002', 'status' => Dispatch::STATUS_DISPATCHED,
            'origin_branch_id' => $this->sj->id, 'destination_branch_id' => $this->lim->id,
            'created_by' => $this->admin->id, // sin chofer asignado
        ]);

        Livewire::actingAs($this->chofer)
            ->test(ChoferPanel::class)
            ->assertSee($mio->code)
            ->assertDontSee('CIE-000002');
    }

    public function test_el_chofer_marca_una_guia_escaneando(): void
    {
        $cierre = $this->cierreAsignado();
        $guia = $cierre->guides->first();

        Livewire::actingAs($this->chofer)
            ->test(ChoferPanel::class)
            ->call('abrirCierre', $cierre->id)
            ->set('scanCode', $guia->code)
            ->call('escanear')
            ->assertSet('feedbackType', 'success');

        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $guia->fresh()->status);
    }

    public function test_un_codigo_inexistente_avisa_sin_romper(): void
    {
        $cierre = $this->cierreAsignado();

        Livewire::actingAs($this->chofer)
            ->test(ChoferPanel::class)
            ->call('abrirCierre', $cierre->id)
            ->set('scanCode', 'SJ-LIM-99999')
            ->call('escanear')
            ->assertSet('feedbackType', 'error')
            ->assertSee('No existe ninguna guía');
    }

    public function test_el_chofer_entrega_con_firma_desde_la_calle(): void
    {
        $cierre = $this->cierreAsignado();
        $guia = $cierre->guides->first();

        Livewire::actingAs($this->chofer)
            ->test(ChoferPanel::class)
            ->call('abrirCierre', $cierre->id)
            ->set('scanCode', $guia->code)
            ->call('escanear')
            ->call('abrirEntrega', $guia->id)
            ->assertSet('receivedByName', 'José Fernández')
            ->set('receivedByName', 'Carlos Umaña')
            ->call('entregar')
            ->assertSet('feedbackType', 'success');

        $guia->refresh();

        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->status);
        $this->assertSame('Carlos Umaña', $guia->received_by_name);
    }

    public function test_el_chofer_reporta_un_problema_desde_la_calle(): void
    {
        $cierre = $this->cierreAsignado();
        $guia = $cierre->guides->first();

        Livewire::actingAs($this->chofer)
            ->test(ChoferPanel::class)
            ->call('abrirCierre', $cierre->id)
            ->call('abrirIncidencia', $guia->id)
            ->set('incidentType', GuideIncident::TYPE_ABSENT)
            ->set('incidentDescription', 'Nadie en la dirección, se dejó aviso')
            ->call('registrarIncidencia')
            ->assertSet('feedbackType', 'success');

        $this->assertSame(1, $guia->fresh()->incidents()->count());
    }
}
