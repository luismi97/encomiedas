<?php

namespace Tests\Feature\Hacienda;

use App\Livewire\Hacienda\PendingQueue;
use App\Models\ElectronicInvoice;
use App\Models\User;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RechazoTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    /** Respuesta real de Hacienda: el detalle trae codigo y mensaje por error. */
    private function respuestaRechazo(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<MensajeHacienda>'
            . '<Clave>50616082610123456789</Clave>'
            . '<EstadoMensaje>rechazado</EstadoMensaje>'
            . '<DetalleMensaje>-312, ""El consecutivo del comprobante ya fue registrado"", 2, 1039 '
            . '-488, ""La sumatoria del desglose no coincide con las lineas de detalle"", 5, 220</DetalleMensaje>'
            . '</MensajeHacienda>';
    }

    private function comprobanteEnviado(): ElectronicInvoice
    {
        Storage::fake('hacienda');
        $this->companySettings();

        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        $ei->forceFill([
            'status' => ElectronicInvoice::STATUS_SENT,
            'last_attempt_at' => now(),
        ])->save();

        return $ei->fresh();
    }

    private function fakeHacienda(array $reception): void
    {
        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'token-de-prueba'], 200),
            '*recepcion*' => $reception['response'],
        ]);
    }

    public function test_el_rechazo_guarda_cada_error_con_su_codigo_y_descripcion(): void
    {
        $ei = $this->comprobanteEnviado();

        $this->fakeHacienda(['response' => Http::response([
            'ind-estado'    => 'rechazado',
            'respuesta-xml' => base64_encode($this->respuestaRechazo()),
        ], 200)]);

        app(ElectronicBillingService::class)->pollStatus($ei);
        $ei->refresh();

        $this->assertSame(ElectronicInvoice::STATUS_REJECTED, $ei->status);
        $this->assertNotNull($ei->rejected_at);

        $errores = $ei->rejectionErrors();
        $this->assertCount(2, $errores);

        $this->assertSame('-312', $errores[0]['code']);
        $this->assertSame('Número consecutivo duplicado', $errores[0]['description']);
        $this->assertStringContainsString('consecutivo del comprobante ya fue registrado', $errores[0]['message']);

        $this->assertSame('-488', $errores[1]['code']);
        $this->assertSame('Desglose de impuestos incompleto respecto a las líneas', $errores[1]['description']);
    }

    public function test_el_xml_de_respuesta_queda_guardado_para_consultarlo(): void
    {
        $ei = $this->comprobanteEnviado();

        $this->fakeHacienda(['response' => Http::response([
            'ind-estado'    => 'rechazado',
            'respuesta-xml' => base64_encode($this->respuestaRechazo()),
        ], 200)]);

        app(ElectronicBillingService::class)->pollStatus($ei);
        $ei->refresh();

        $this->assertNotNull($ei->response_xml_path);
        Storage::disk('hacienda')->assertExists($ei->response_xml_path);

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->actingAs($admin)
            ->get(route('electronic-invoices.response-xml', $ei))
            ->assertOk();
    }

    public function test_un_rechazo_sin_detalle_no_deja_al_usuario_sin_explicacion(): void
    {
        $ei = $this->comprobanteEnviado();

        $this->fakeHacienda(['response' => Http::response(['ind-estado' => 'rechazado'], 200)]);

        app(ElectronicBillingService::class)->pollStatus($ei);
        $ei->refresh();

        $this->assertSame(ElectronicInvoice::STATUS_REJECTED, $ei->status);
        $this->assertStringContainsString('XML de respuesta', $ei->error_message);
    }

    public function test_el_motivo_se_puede_ver_desde_la_cola(): void
    {
        $ei = $this->comprobanteEnviado();

        $this->fakeHacienda(['response' => Http::response([
            'ind-estado'    => 'rechazado',
            'respuesta-xml' => base64_encode($this->respuestaRechazo()),
        ], 200)]);

        app(ElectronicBillingService::class)->pollStatus($ei);

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(PendingQueue::class)
            ->set('tab', 'rejected')
            ->call('showRejection', $ei->id)
            ->assertSee('Número consecutivo duplicado')
            ->assertSee('Desglose de impuestos incompleto')
            ->assertSee('-312');
    }

    public function test_reintentar_limpia_el_rechazo_anterior(): void
    {
        $ei = $this->comprobanteEnviado();

        $this->fakeHacienda(['response' => Http::response([
            'ind-estado'    => 'rechazado',
            'respuesta-xml' => base64_encode($this->respuestaRechazo()),
        ], 200)]);

        app(ElectronicBillingService::class)->pollStatus($ei);
        $this->assertNotEmpty($ei->fresh()->rejectionErrors());

        \Illuminate\Support\Facades\Bus::fake();
        app(ElectronicBillingService::class)->retry($ei->fresh());

        $ei->refresh();
        $this->assertNull($ei->rejection_details);
        $this->assertNull($ei->rejected_at);
        $this->assertSame([], $ei->rejectionErrors());
    }

    /**
     * Hacienda respondiendo 404 significa que el comprobante nunca llego.
     * Dejarlo en "enviado" lo esconde para siempre.
     */
    public function test_si_hacienda_no_conoce_la_clave_el_comprobante_vuelve_a_error(): void
    {
        $ei = $this->comprobanteEnviado();
        $ei->forceFill(['last_attempt_at' => now()->subMinutes(20)])->save();

        $this->fakeHacienda(['response' => Http::response([], 404)]);

        app(ElectronicBillingService::class)->pollStatus($ei->fresh());
        $ei->refresh();

        $this->assertSame(ElectronicInvoice::STATUS_ERROR, $ei->status);
        $this->assertStringContainsString('no llegó', $ei->error_message);
    }

    public function test_un_404_recien_transmitido_no_se_da_por_perdido(): void
    {
        $ei = $this->comprobanteEnviado();
        $ei->forceFill(['last_attempt_at' => now()->subMinute()])->save();

        $this->fakeHacienda(['response' => Http::response([], 404)]);

        app(ElectronicBillingService::class)->pollStatus($ei->fresh());

        // La consulta puede ir por delante de la recepcion: se le da holgura.
        $this->assertSame(ElectronicInvoice::STATUS_SENT, $ei->fresh()->status);
    }
}
