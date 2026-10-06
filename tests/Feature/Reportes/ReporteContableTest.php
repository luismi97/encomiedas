<?php

namespace Tests\Feature\Reportes;

use App\Livewire\Reportes\ReportePanel;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\EnviarReporteContable;
use App\Services\ReporteContable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Hacienda\BuildsHaciendaFixtures;
use Tests\TestCase;

/**
 * El reporte del contador: cuánto se vendió y cuánto IVA declarar en un
 * rango de fechas, contando SOLO lo que Hacienda aceptó en producción.
 */
class ReporteContableTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private User $admin;
    private Invoice $guia;
    private int $consecutivo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companySettings();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'adm', 'email' => 'adm@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->guia = $this->deliveredInvoice($this->branch());
    }

    private function comprobante(string $tipo, float $venta, float $iva, string $estado = ElectronicInvoice::STATUS_ACCEPTED, string $ambiente = 'prod', string $fecha = '2026-09-15'): ElectronicInvoice
    {
        $n = ++$this->consecutivo;

        return ElectronicInvoice::create([
            'branch_id' => $this->branch()->id, 'invoice_id' => $this->guia->id,
            'document_type' => $tipo, 'clave' => str_pad((string) $n, 50, '0', STR_PAD_LEFT),
            'consecutivo' => str_pad((string) $n, 20, '0', STR_PAD_LEFT), 'security_code' => '12345678',
            'environment' => $ambiente, 'issued_at' => $fecha . ' 10:00:00',
            'receptor_data' => ['nombre' => 'Cliente ' . $n, 'numero' => '11234056' . $n],
            'currency_code' => 'CRC', 'exchange_rate' => 1,
            'sub_total' => $venta, 'total_tax' => $iva, 'total' => $venta + $iva, 'status' => $estado,
        ]);
    }

    private function generar(): array
    {
        return app(ReporteContable::class)->generar('2026-09-01', '2026-09-30');
    }

    public function test_suma_solo_lo_aceptado_en_produccion_y_resta_las_notas_de_credito(): void
    {
        $this->comprobante('01', 10000, 1300);
        $this->comprobante('04', 5000, 650);
        $this->comprobante('03', 2000, 260);                                        // nota de crédito: resta
        $this->comprobante('01', 9999, 1299, ElectronicInvoice::STATUS_REJECTED);     // no existe para Hacienda
        $this->comprobante('01', 8888, 1155, ElectronicInvoice::STATUS_SENT);         // sin respuesta
        $this->comprobante('01', 7777, 1011, ambiente: 'sandbox');                     // prueba
        $this->comprobante('01', 6666, 866, fecha: '2026-10-01');                      // fuera del período

        $r = $this->generar();

        $this->assertEquals(13000, $r['neto']['venta']);
        $this->assertEquals(1690, $r['neto']['iva']);
        $this->assertEquals(14690, $r['neto']['total']);
        $this->assertCount(3, $r['detalle']);
        $this->assertSame(1, $r['pendientes']);
        $this->assertSame(1, $r['deSandbox']);
    }

    public function test_desglosa_por_tarifa(): void
    {
        $this->comprobante('01', 10000, 1300);
        $this->comprobante('04', 4000, 0);

        $tarifas = collect($this->generar()['porTarifa'])->keyBy('tarifa');

        $this->assertEquals(1300, $tarifas['IVA 13%']['iva']);
        $this->assertEquals(4000, $tarifas['Exento (0%)']['venta']);
    }

    public function test_se_ve_en_reportes_y_se_descarga_en_pdf(): void
    {
        $this->comprobante('01', 10000, 1300);

        Livewire::actingAs($this->admin)
            ->test(ReportePanel::class)
            ->set('from', '2026-09-01')->set('to', '2026-09-30')
            ->set('reporte', 'contable')
            ->assertSee('IVA a declarar')
            ->assertSee('₡1,300.00');

        $this->actingAs($this->admin)
            ->get(route('reportes.contable.pdf', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_se_envia_por_correo_con_el_pdf_y_recuerda_el_correo(): void
    {
        Notification::fake();
        $this->comprobante('01', 10000, 1300);

        Livewire::actingAs($this->admin)
            ->test(ReportePanel::class)
            ->set('from', '2026-09-01')->set('to', '2026-09-30')
            ->set('reporte', 'contable')
            ->set('correoContador', 'contador@despacho.test')
            ->call('enviarAlContador')
            ->assertHasNoErrors();

        Notification::assertSentOnDemand(EnviarReporteContable::class, function ($n, $canales, $notifiable) {
            $correo = $n->toMail($notifiable);

            return $notifiable->routes['mail'] === 'contador@despacho.test'
                && count($correo->rawAttachments) === 1
                && str_contains(implode(' ', $correo->introLines), '₡1,300.00');
        });

        $this->assertSame('contador@despacho.test', CompanySetting::instance()->accountant_email);
    }

    /** El reporte de facturación electrónica agrupa por estado con su nombre. */
    public function test_el_reporte_de_facturacion_electronica_abre_con_comprobantes(): void
    {
        $this->comprobante('01', 10000, 1300);
        $this->comprobante('01', 5000, 650, ElectronicInvoice::STATUS_REJECTED);

        Livewire::actingAs($this->admin)
            ->test(ReportePanel::class)
            ->set('from', '2020-01-01')->set('to', now()->toDateString())
            ->set('reporte', 'hacienda')
            ->assertOk()
            ->assertSee('Aceptado por Hacienda')
            ->assertSee('Rechazado por Hacienda');
    }

    public function test_el_cajero_no_lo_ve(): void
    {
        $cajero = User::create(['name' => 'Caj', 'username' => 'caj', 'email' => 'caj@t.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->branch()->id]);

        Livewire::actingAs($cajero)
            ->test(ReportePanel::class)
            ->assertDontSee('Reporte contable')
            ->set('reporte', 'contable')
            ->assertSet('reporte', 'estados');

        $this->actingAs($cajero)
            ->get(route('reportes.contable.pdf', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertForbidden();

        // Reportes completo es solo de administración: ni la pantalla ni el menú.
        $this->actingAs($cajero)->get(route('reportes.index'))->assertForbidden();
        $this->actingAs($cajero)->get(route('dashboard'))->assertDontSee(route('reportes.index'));
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('reportes.index'));
    }
}
