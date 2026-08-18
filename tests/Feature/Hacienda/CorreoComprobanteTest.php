<?php

namespace Tests\Feature\Hacienda;

use App\Models\ElectronicInvoice;
use App\Notifications\SendElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\PdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El correo al cliente cuando Hacienda acepta: PDF, XML firmado y XML de
 * respuesta. Los tres son lo que el receptor necesita para su contabilidad.
 */
class CorreoComprobanteTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function comprobanteAceptado(): ElectronicInvoice
    {
        Storage::fake('hacienda');
        $this->companySettings();

        $this->app->bind(PdfGenerator::class, fn () => new class extends PdfGenerator {
            public function __construct() {}
            public function generate(ElectronicInvoice $ei): string
            {
                $ruta = 'pdf/' . $ei->clave . '.pdf';
                Storage::disk('hacienda')->put($ruta, '%PDF-1.4 contenido de prueba');
                $ei->forceFill(['pdf_path' => $ruta])->save();

                return $ruta;
            }
        });

        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        // El XML firmado lo deja el envío; aquí se simula porque no se transmite.
        Storage::disk('hacienda')->put('comprobantes/' . $ei->clave . '.xml', '<FacturaElectronica/>');

        $ei->forceFill([
            'status' => ElectronicInvoice::STATUS_SENT,
            'signed_xml_path' => 'comprobantes/' . $ei->clave . '.xml',
            'last_attempt_at' => now(),
        ])->save();

        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'tok'], 200),
            '*recepcion*' => Http::response([
                'ind-estado'    => 'aceptado',
                'respuesta-xml' => base64_encode('<MensajeHacienda><EstadoMensaje>aceptado</EstadoMensaje></MensajeHacienda>'),
            ], 200),
        ]);

        app(ElectronicBillingService::class)->pollStatus($ei->fresh());

        return $ei->fresh();
    }

    public function test_al_aceptarse_se_envia_el_correo_al_receptor(): void
    {
        Notification::fake();

        $this->comprobanteAceptado();

        Notification::assertSentOnDemand(
            SendElectronicInvoice::class,
            fn ($n, $canales, $notifiable) => $notifiable->routes['mail'] === 'jose@cliente.test'
        );
    }

    /** Los tres adjuntos: es lo que el receptor necesita para su contabilidad. */
    public function test_el_correo_lleva_pdf_xml_firmado_y_xml_de_respuesta(): void
    {
        $ei = $this->comprobanteAceptado();

        $this->assertNotNull($ei->pdf_path, 'Falta el PDF');
        $this->assertNotNull($ei->signed_xml_path, 'Falta el XML firmado');
        $this->assertNotNull($ei->response_xml_path, 'Falta el XML de respuesta de Hacienda');

        // Se arma el mensaje de verdad y se revisan sus adjuntos.
        $mensaje = (new SendElectronicInvoice($ei))->toMail(
            (object) ['routes' => ['mail' => 'jose@cliente.test']]
        );

        $nombres = collect($mensaje->rawAttachments)->pluck('name');

        $this->assertTrue($nombres->contains($ei->clave . '.pdf'));
        $this->assertTrue($nombres->contains($ei->clave . '.xml'));
        $this->assertTrue($nombres->contains($ei->clave . '-respuesta.xml'));
        $this->assertCount(3, $nombres);
    }

    public function test_el_correo_nombra_los_archivos_con_la_clave(): void
    {
        $ei = $this->comprobanteAceptado();

        $mensaje = (new SendElectronicInvoice($ei))->toMail(
            (object) ['routes' => ['mail' => 'jose@cliente.test']]
        );

        foreach (collect($mensaje->rawAttachments)->pluck('name') as $nombre) {
            $this->assertStringStartsWith($ei->clave, $nombre,
                'El archivo debe llevar la clave: es como el contador lo identifica.');
        }
    }

    /** Un comprobante rechazado no se le manda al cliente. */
    public function test_un_rechazo_no_dispara_correo_al_cliente(): void
    {
        Notification::fake();
        Storage::fake('hacienda');
        $this->companySettings();

        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));
        $ei->forceFill(['status' => ElectronicInvoice::STATUS_SENT, 'last_attempt_at' => now()])->save();

        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'tok'], 200),
            '*recepcion*' => Http::response(['ind-estado' => 'rechazado'], 200),
        ]);

        app(ElectronicBillingService::class)->pollStatus($ei->fresh());

        Notification::assertNothingSent();
    }

    /** La notificación es encolada: firmar y adjuntar no bloquea el request. */
    public function test_el_correo_va_por_la_cola(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new SendElectronicInvoice(new ElectronicInvoice())
        );
    }
}
