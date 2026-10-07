<?php

namespace Tests\Feature\Reportes;

use App\Livewire\Reportes\ReportePanel;
use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Feature\Hacienda\BuildsHaciendaFixtures;
use Tests\TestCase;

/**
 * Qué se le ha facturado a cada cliente: por la cédula que quedó en el
 * comprobante, que es lo que vale ante Hacienda.
 */
class FacturasPorClienteTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function comprobante(string $codigo, array $guia, string $estado = ElectronicInvoice::STATUS_ACCEPTED): ElectronicInvoice
    {
        $ei = app(ElectronicBillingService::class)->queueForInvoice(
            $this->deliveredInvoice($this->branch(), array_merge(['code' => $codigo], $guia))
        );
        $ei->forceFill(['status' => $estado, 'total' => 11300])->save();

        return $ei->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->companySettings();
    }

    public function test_resume_por_cedula_y_muestra_el_detalle_de_un_cliente(): void
    {
        $jose = ['recipient_name' => 'José Fernández', 'recipient_identification' => '112340567'];
        $ana = ['recipient_name' => 'Ana Mora', 'recipient_identification' => '203450678'];

        $this->comprobante('ENC-1', $jose);
        $this->comprobante('ENC-2', $jose, ElectronicInvoice::STATUS_REJECTED);
        $this->comprobante('ENC-3', $ana);
        // Los tiquetes no tienen a quién atribuirse.
        $this->comprobante('ENC-4', ['bill_type' => \App\Models\Invoice::BILL_TICKET]);

        $admin = \App\Models\User::where('email', 'admin@prueba.test')->firstOrFail();

        $panel = Livewire::actingAs($admin)->test(ReportePanel::class)->set('reporte', 'clientes');
        $filas = collect($panel->viewData('datos')['filas'])->keyBy('cedula');

        $this->assertCount(2, $filas);
        $this->assertSame(2, $filas['112340567']['cantidad']);
        $this->assertSame(1, $filas['112340567']['aceptados']);
        $this->assertSame(1, $filas['112340567']['problemas']);
        // Lo rechazado no cuenta como facturado.
        $this->assertEqualsWithDelta(11300.0, $filas['112340567']['monto'], 0.01);

        $panel->call('verCliente', '112340567')
            ->assertSee('José Fernández')
            ->assertSee('ENC-1')
            ->assertSee('ENC-2')
            ->assertDontSee('ENC-3');
    }

    public function test_desde_clientes_se_llega_directo_al_detalle(): void
    {
        $this->comprobante('ENC-1', ['recipient_name' => 'José Fernández', 'recipient_identification' => '112340567']);
        $admin = \App\Models\User::where('email', 'admin@prueba.test')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('reportes.index', ['reporte' => 'clientes', 'cliente' => '112340567']))
            ->assertOk()
            ->assertSee('José Fernández')
            ->assertSee('ENC-1');
    }
}
