<?php

namespace Tests\Feature\Hacienda;

use App\Livewire\Invoices\InvoiceShow;
use App\Models\ActivityLog;
use App\Models\ElectronicInvoice;
use App\Models\User;
use App\Notifications\SendElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\PdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * El comprobante aceptado llega también al correo de la empresa registrado en
 * sus datos de Hacienda, y un administrador lo puede volver a mandar —al
 * cliente o a otro correo— cuando haga falta.
 */
class CopiaYReenvioDeComprobanteTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Storage::fake('hacienda');

        // El PDF real se prueba aparte: acá solo importa que exista.
        $this->app->bind(PdfGenerator::class, fn () => new class extends PdfGenerator {
            public function __construct() {}
            public function generate(ElectronicInvoice $ei): string
            {
                $ruta = 'pdf/' . $ei->clave . '.pdf';
                Storage::disk('hacienda')->put($ruta, '%PDF-1.4 prueba');
                $ei->forceFill(['pdf_path' => $ruta])->save();

                return $ruta;
            }
        });
    }

    private function enviado(array $empresa = []): ElectronicInvoice
    {
        $this->companySettings($empresa);

        $ei = app(ElectronicBillingService::class)->queueForInvoice($this->deliveredInvoice($this->branch()));
        Storage::disk('hacienda')->put('firmados/' . $ei->clave . '.xml', '<FacturaElectronica/>');
        $ei->forceFill([
            'status' => ElectronicInvoice::STATUS_SENT,
            'signed_xml_path' => 'firmados/' . $ei->clave . '.xml',
            'last_attempt_at' => now(),
        ])->save();

        return $ei->fresh();
    }

    private function aceptar(ElectronicInvoice $ei): ElectronicInvoice
    {
        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'tok'], 200),
            '*recepcion*' => Http::response([
                'ind-estado' => 'aceptado',
                'respuesta-xml' => base64_encode('<MensajeHacienda/>'),
            ], 200),
        ]);

        app(ElectronicBillingService::class)->pollStatus($ei);

        return $ei->fresh();
    }

    private function enviadoA(string $correo): bool
    {
        try {
            Notification::assertSentOnDemand(
                SendElectronicInvoice::class,
                fn ($n, $canales, $notifiable) => $notifiable->routes['mail'] === $correo
            );

            return true;
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            return false;
        }
    }

    // ── Copia a la empresa ────────────────────────────────────────────

    public function test_al_aceptarse_llega_al_cliente_y_al_correo_de_la_empresa(): void
    {
        Notification::fake();

        $this->aceptar($this->enviado());

        $this->assertTrue($this->enviadoA('jose@cliente.test'), 'Al cliente.');
        $this->assertTrue($this->enviadoA('facturacion@encomiendas.test'), 'Copia al correo de los datos de Hacienda.');
    }

    public function test_sin_correo_del_cliente_igual_llega_la_copia_a_la_empresa(): void
    {
        Notification::fake();

        $ei = $this->enviado();
        $ei->invoice->forceFill(['recipient_email' => null])->save();
        $ei->forceFill(['receptor_data' => array_merge($ei->receptor_data, ['email' => null])])->save();

        $this->aceptar($ei->fresh());

        Notification::assertSentOnDemandTimes(SendElectronicInvoice::class, 1);
        $this->assertTrue($this->enviadoA('facturacion@encomiendas.test'));
    }

    /** Si el cliente es la propia empresa, no le llegan dos iguales. */
    public function test_no_manda_dos_veces_al_mismo_correo(): void
    {
        Notification::fake();

        $this->aceptar($this->enviado(['email' => 'JOSE@cliente.test']));

        Notification::assertSentOnDemandTimes(SendElectronicInvoice::class, 1);
    }

    public function test_un_rechazo_no_manda_copia(): void
    {
        Notification::fake();
        $ei = $this->enviado();

        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'tok'], 200),
            '*recepcion*' => Http::response(['ind-estado' => 'rechazado'], 200),
        ]);
        app(ElectronicBillingService::class)->pollStatus($ei);

        Notification::assertNothingSent();
    }

    // ── Reenvío ───────────────────────────────────────────────────────

    public function test_reenvia_un_aceptado_a_otro_correo(): void
    {
        $ei = $this->aceptar($this->enviado());
        Notification::fake();

        app(ElectronicBillingService::class)->reenviarCorreo($ei, 'contador@cliente.test');

        $this->assertTrue($this->enviadoA('contador@cliente.test'));
        Notification::assertSentOnDemandTimes(SendElectronicInvoice::class, 1);
    }

    public function test_no_reenvia_lo_que_hacienda_no_acepto(): void
    {
        $ei = $this->enviado();
        Notification::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('aceptado');

        app(ElectronicBillingService::class)->reenviarCorreo($ei, 'otro@cliente.test');
    }

    public function test_un_correo_invalido_se_rechaza_con_motivo(): void
    {
        $ei = $this->aceptar($this->enviado());

        $this->expectExceptionMessage('no es válido');

        app(ElectronicBillingService::class)->reenviarCorreo($ei, 'no-es-correo');
    }

    /** El reenvío rehace el PDF: así sale con el formato vigente. */
    public function test_el_reenvio_rehace_el_pdf(): void
    {
        $ei = $this->aceptar($this->enviado());
        Storage::disk('hacienda')->delete($ei->pdf_path);
        Notification::fake();

        app(ElectronicBillingService::class)->reenviarCorreo($ei, 'contador@cliente.test');

        $this->assertTrue(Storage::disk('hacienda')->exists($ei->fresh()->pdf_path));
    }

    // ── Desde la guía ─────────────────────────────────────────────────

    public function test_el_admin_reenvia_desde_la_guia_con_el_correo_del_cliente_propuesto(): void
    {
        $ei = $this->aceptar($this->enviado());
        Notification::fake();
        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(InvoiceShow::class, ['invoice' => $ei->invoice])
            ->assertSee('Reenviar por correo')
            ->call('openResendForm', $ei->id)
            ->assertSet('resendEmail', 'jose@cliente.test')
            ->set('resendEmail', 'contador@cliente.test')
            ->call('reenviarComprobante')
            ->assertSet('showResendForm', false);

        $this->assertTrue($this->enviadoA('contador@cliente.test'));
        $this->assertTrue(ActivityLog::where('action', 'hacienda_resent')->exists(), 'Queda en la bitácora.');
    }

    public function test_un_cajero_no_reenvia(): void
    {
        $ei = $this->aceptar($this->enviado());
        Notification::fake();
        $cajero = User::create(['name' => 'Caj', 'username' => 'caj', 'email' => 'caj@t.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->branch()->id]);

        Livewire::actingAs($cajero)
            ->test(InvoiceShow::class, ['invoice' => $ei->invoice])
            ->set('resendId', $ei->id)
            ->set('resendEmail', 'otro@x.test')
            ->call('reenviarComprobante');

        Notification::assertNothingSent();
    }

    /** El id llega del navegador: un comprobante de otra guía no se manda. */
    public function test_no_reenvia_un_comprobante_de_otra_guia(): void
    {
        $ei = $this->aceptar($this->enviado());
        $otra = $this->deliveredInvoice($this->branch(), ['code' => 'ENC-000002']);
        Notification::fake();
        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(InvoiceShow::class, ['invoice' => $otra])
            ->set('resendId', $ei->id)
            ->set('resendEmail', 'otro@x.test')
            ->call('reenviarComprobante');

        Notification::assertNothingSent();
    }
}
