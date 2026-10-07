<?php

namespace Tests\Feature\Hacienda;

use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\PdfGenerator;
use App\Services\Hacienda\XadesSigner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Hacienda rechaza una FechaEmision anterior a la generación del comprobante
 * (Anexos y Estructuras v4.4; solo se permite con situación «sin internet»).
 * El comprobante se reserva al recibir el paquete y el administrador lo envía
 * cuando decide, a veces días después: tiene que salir con la fecha del envío.
 */
class FechaDeEmisionAlEnviarTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    /** Lo que se le mandó a Hacienda en el POST de recepción. */
    private array $enviado = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('hacienda');
        Notification::fake();
        $this->companySettings();
        Storage::disk('hacienda')->put('certs/prueba.p12', 'p12-de-prueba');

        // Firmar no hace al caso: lo que se prueba es la fecha que lleva.
        $this->app->bind(XadesSigner::class, fn () => new class extends XadesSigner {
            public function __construct() {}
            public function sign(string $xml, string $p12Contents, string $pin): string { return $xml; }
        });

        $this->app->bind(PdfGenerator::class, fn () => new class extends PdfGenerator {
            public function __construct() {}
            public function generate(ElectronicInvoice $electronicInvoice): string { return ''; }
        });

        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'tok'], 200),
            '*recepcion*' => function (Request $request) {
                $this->enviado = $request->data();

                return Http::response('', 202);
            },
        ]);
    }

    private function reservadoHace(int $dias): ElectronicInvoice
    {
        $this->travelTo(now()->subDays($dias));
        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($this->deliveredInvoice($this->branch()));
        $this->travelBack();

        return $comprobante;
    }

    public function test_un_comprobante_reservado_hace_dias_sale_con_la_fecha_del_envio(): void
    {
        $comprobante = $this->reservadoHace(3);
        $claveReservada = $comprobante->clave;
        $hoy = Carbon::now(config('app.timezone'));

        app(ElectronicBillingService::class)->send($comprobante, fromQueue: true);

        $enviado = $comprobante->fresh();
        $this->assertSame(ElectronicInvoice::STATUS_SENT, $enviado->status);
        $this->assertTrue($enviado->issued_at->isSameDay($hoy), 'La fecha de emisión es la del envío.');

        // La fecha va dentro de la clave; el resto es lo reservado.
        $this->assertSame($hoy->format('dmy'), substr($enviado->clave, 3, 6));
        $this->assertSame(substr($claveReservada, 9), substr($enviado->clave, 9), 'Mismo consecutivo y código de seguridad.');
        $this->assertSame($comprobante->consecutivo, $enviado->consecutivo);

        // Y es lo que viaja: en el XML, en el payload y con la clave nueva.
        $xml = Storage::disk('hacienda')->get($enviado->signed_xml_path);
        $this->assertStringContainsString('<FechaEmision>' . $hoy->format('Y-m-d'), $xml);
        $this->assertStringContainsString('<Clave>' . $enviado->clave . '</Clave>', $xml);
        $this->assertSame($enviado->clave, $this->enviado['clave']);
        $this->assertStringStartsWith($hoy->format('Y-m-d'), $this->enviado['fecha']);
    }

    /**
     * Con un intento encima, Hacienda pudo haberlo recibido con esa clave:
     * cambiarla al reintentar declararía dos comprobantes por una encomienda.
     */
    public function test_un_comprobante_que_ya_se_intento_transmitir_conserva_su_clave(): void
    {
        $comprobante = $this->reservadoHace(3);
        $comprobante->forceFill(['status' => ElectronicInvoice::STATUS_ERROR, 'send_attempts' => 1])->save();
        $claveOriginal = $comprobante->clave;
        $fechaOriginal = $comprobante->issued_at;

        app(ElectronicBillingService::class)->send($comprobante->fresh(), fromQueue: true);

        $reenviado = $comprobante->fresh();
        $this->assertSame($claveOriginal, $reenviado->clave);
        $this->assertTrue($fechaOriginal->equalTo($reenviado->issued_at));
        $this->assertSame($claveOriginal, $this->enviado['clave']);
    }

    public function test_la_clave_es_provisional_hasta_que_sale(): void
    {
        $comprobante = $this->reservadoHace(0);
        $this->assertFalse($comprobante->claveEsDefinitiva());

        $comprobante->status = ElectronicInvoice::STATUS_QUEUED;
        $this->assertFalse($comprobante->claveEsDefinitiva());

        $comprobante->status = ElectronicInvoice::STATUS_ACCEPTED;
        $this->assertTrue($comprobante->claveEsDefinitiva());

        $comprobante->forceFill(['status' => ElectronicInvoice::STATUS_ERROR, 'send_attempts' => 1]);
        $this->assertTrue($comprobante->claveEsDefinitiva(), 'Pudo haber llegado.');

        $comprobante->status = ElectronicInvoice::STATUS_REJECTED;
        $this->assertFalse($comprobante->claveEsDefinitiva(), 'Rechazada: se rehace con otra.');
    }
}
