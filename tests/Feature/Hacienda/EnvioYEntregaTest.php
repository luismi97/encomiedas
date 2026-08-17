<?php

namespace Tests\Feature\Hacienda;

use App\Jobs\SendElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Notifications\SendElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\PdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class EnvioYEntregaTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function pendingInvoice(): ElectronicInvoice
    {
        $this->companySettings();

        return app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));
    }

    public function test_el_envio_se_delega_a_la_cola_y_no_bloquea_el_request(): void
    {
        Bus::fake();
        $electronicInvoice = $this->pendingInvoice();

        app(ElectronicBillingService::class)->queueSend($electronicInvoice);

        Bus::assertDispatched(
            SendElectronicInvoiceJob::class,
            fn (SendElectronicInvoiceJob $job) => $job->electronicInvoiceId === $electronicInvoice->id
        );
        $this->assertSame(ElectronicInvoice::STATUS_QUEUED, $electronicInvoice->fresh()->status);
    }

    public function test_un_comprobante_ya_encolado_no_se_encola_dos_veces(): void
    {
        Bus::fake();
        $service = app(ElectronicBillingService::class);
        $electronicInvoice = $this->pendingInvoice();

        $service->queueSend($electronicInvoice);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya está en la cola');

        // Un segundo clic al botón de enviar no puede transmitir de nuevo:
        // serían dos comprobantes ante Hacienda para la misma encomienda.
        $service->queueSend($electronicInvoice->fresh());
    }

    public function test_el_envio_en_bloque_reporta_encolados_y_errores(): void
    {
        Bus::fake();
        $service = app(ElectronicBillingService::class);
        $electronicInvoice = $this->pendingInvoice();
        $service->queueSend($electronicInvoice);

        $result = $service->sendBatch([$electronicInvoice->id]);

        $this->assertEmpty($result['queued']);
        $this->assertCount(1, $result['errors']);
    }

    public function test_si_el_job_muere_el_comprobante_vuelve_a_estado_de_error(): void
    {
        Bus::fake();
        $electronicInvoice = $this->pendingInvoice();
        app(ElectronicBillingService::class)->queueSend($electronicInvoice);

        (new SendElectronicInvoiceJob($electronicInvoice->id))->failed(new RuntimeException('sin conexión'));

        $fresh = $electronicInvoice->fresh();
        $this->assertSame(ElectronicInvoice::STATUS_ERROR, $fresh->status);
        $this->assertStringContainsString('sin conexión', $fresh->error_message);
    }

    public function test_cuando_hacienda_acepta_se_le_entrega_el_comprobante_al_receptor(): void
    {
        Notification::fake();
        Storage::fake('hacienda');

        // El PDF se genera con dompdf y no aporta nada a esta prueba.
        $this->instance(PdfGenerator::class, new class extends PdfGenerator {
            public function __construct()
            {
            }

            public function generate(ElectronicInvoice $electronicInvoice): string
            {
                return '';
            }
        });

        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'token-de-prueba'], 200),
            '*recepcion*' => Http::response([
                'ind-estado'    => 'aceptado',
                'respuesta-xml' => base64_encode('<MensajeHacienda><EstadoMensaje>aceptado</EstadoMensaje></MensajeHacienda>'),
            ], 200),
        ]);

        $electronicInvoice = $this->pendingInvoice();
        $electronicInvoice->forceFill(['status' => ElectronicInvoice::STATUS_SENT])->save();

        app(ElectronicBillingService::class)->pollStatus($electronicInvoice->fresh());

        $this->assertSame(ElectronicInvoice::STATUS_ACCEPTED, $electronicInvoice->fresh()->status);

        Notification::assertSentOnDemand(
            SendElectronicInvoice::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'jose@cliente.test'
        );
    }

    public function test_el_ambiente_de_produccion_exige_el_flag_del_servidor(): void
    {
        $settings = $this->companySettings(['environment' => 'prod']);

        config()->set('hacienda.live', false);
        $this->assertFalse($settings->isProduction());
        $this->assertSame(
            config('hacienda.environments.sandbox.reception_url'),
            $settings->environmentConfig()['reception_url'],
            'Sin HACIENDA_LIVE en el servidor, una base clonada de producción debe caer a sandbox.'
        );

        config()->set('hacienda.live', true);
        $this->assertTrue($settings->isProduction());
        $this->assertSame(
            config('hacienda.environments.prod.reception_url'),
            $settings->environmentConfig()['reception_url']
        );
    }
}
