<?php

namespace Tests\Feature\Hacienda;

use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\PdfGenerator;
use App\Services\Hacienda\XadesSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un timeout al transmitir no significa que el documento no llegó: Hacienda
 * pudo recibirlo y estar procesándolo. Darlo por fallido sin preguntar invita
 * a reenviarlo y a terminar con dos comprobantes para una misma encomienda.
 */
class ReconciliacionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function preparar(): ElectronicInvoice
    {
        Storage::fake('hacienda');
        Notification::fake();
        $this->companySettings();

        // Firmar y renderizar el PDF no hacen al caso: lo que se prueba es qué
        // pasa cuando la transmisión queda en duda.
        Storage::disk('hacienda')->put('certs/prueba.p12', 'p12-de-prueba');

        $this->app->bind(XadesSigner::class, fn () => new class extends XadesSigner {
            public function __construct() {}
            public function sign(string $xml, string $p12Contents, string $pin): string { return $xml; }
        });

        $this->app->bind(PdfGenerator::class, fn () => new class extends PdfGenerator {
            public function __construct() {}
            public function generate(ElectronicInvoice $electronicInvoice): string { return ''; }
        });

        return app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));
    }

    /** POST revienta, GET responde lo que se le indique. */
    private function fakeEnvioCaidoYConsulta(\Closure $consulta): void
    {
        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'tok'], 200),
            '*recepcion*' => function ($request) use ($consulta) {
                if ($request->method() === 'POST') {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return $consulta();
            },
        ]);
    }

    public function test_si_el_envio_se_cae_pero_hacienda_ya_lo_tiene_no_se_marca_error(): void
    {
        $ei = $this->preparar();

        $this->fakeEnvioCaidoYConsulta(fn () => Http::response([
            'ind-estado'    => 'aceptado',
            'respuesta-xml' => base64_encode('<MensajeHacienda><EstadoMensaje>aceptado</EstadoMensaje></MensajeHacienda>'),
        ], 200));

        app(ElectronicBillingService::class)->send($ei, fromQueue: true);

        $ei->refresh();
        $this->assertSame(ElectronicInvoice::STATUS_ACCEPTED, $ei->status);
        $this->assertNull($ei->error_message);
    }

    public function test_si_hacienda_confirma_que_nunca_llego_si_se_marca_error(): void
    {
        $ei = $this->preparar();

        $this->fakeEnvioCaidoYConsulta(fn () => Http::response([], 404));

        app(ElectronicBillingService::class)->send($ei, fromQueue: true);

        $ei->refresh();
        $this->assertSame(ElectronicInvoice::STATUS_ERROR, $ei->status);
        $this->assertStringContainsString('No se pudo confirmar el envío', $ei->error_message);
    }

    public function test_reconcile_devuelve_false_cuando_hacienda_no_conoce_la_clave(): void
    {
        $ei = $this->preparar();

        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'tok'], 200),
            '*recepcion*' => Http::response([], 404),
        ]);

        $this->assertFalse(app(ElectronicBillingService::class)->reconcile($ei->fresh()));
    }

    public function test_reconcile_devuelve_null_cuando_no_se_pudo_averiguar(): void
    {
        $ei = $this->preparar();

        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'tok'], 200),
            '*recepcion*' => Http::response([], 500),
        ]);

        // Ni confirmado ni descartado: no se puede asumir ninguna de las dos.
        $this->assertNull(app(ElectronicBillingService::class)->reconcile($ei->fresh()));
    }

    public function test_reconcile_devuelve_true_y_sincroniza_cuando_hacienda_la_tiene(): void
    {
        $ei = $this->preparar();

        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'tok'], 200),
            '*recepcion*' => Http::response(['ind-estado' => 'procesando'], 200),
        ]);

        $this->assertTrue(app(ElectronicBillingService::class)->reconcile($ei->fresh()));
        $this->assertSame('procesando', $ei->fresh()->hacienda_status);
    }
}
