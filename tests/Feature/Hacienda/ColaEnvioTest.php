<?php

namespace Tests\Feature\Hacienda;

use App\Jobs\SendElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ColaEnvioTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    /**
     * retry_after tiene que superar el timeout del job más lento. Si no, la
     * cola da por colgado un job que sigue corriendo, lo devuelve a la fila y
     * el MISMO comprobante se transmite dos veces a Hacienda.
     */
    public function test_retry_after_supera_el_timeout_del_job_de_envio(): void
    {
        $retryAfter = config('queue.connections.database.retry_after');
        $timeout = (new SendElectronicInvoiceJob(1))->timeout;

        $this->assertGreaterThan(
            $timeout,
            $retryAfter,
            "retry_after ({$retryAfter}s) debe ser mayor que el timeout del job ({$timeout}s), "
            . 'o un envío lento se transmite dos veces a Hacienda.'
        );
    }

    /** uniqueId() solo lo consulta Laravel si la clase declara la interfaz. */
    public function test_el_job_declara_unicidad_de_verdad(): void
    {
        $job = new SendElectronicInvoiceJob(42);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('42', $job->uniqueId());
        $this->assertGreaterThan(0, $job->uniqueFor);
    }

    public function test_encolar_despacha_un_job_con_el_id_del_comprobante(): void
    {
        Queue::fake();
        $this->companySettings();

        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        app(ElectronicBillingService::class)->queueSend($ei);

        Queue::assertPushed(
            SendElectronicInvoiceJob::class,
            fn (SendElectronicInvoiceJob $job) => $job->electronicInvoiceId === $ei->id
        );
    }

    /**
     * Si el job muere para siempre, el comprobante NO puede quedarse en
     * 'queued': desaparecería de pendientes sin haberse enviado nunca.
     */
    public function test_un_job_muerto_devuelve_el_comprobante_a_error(): void
    {
        Bus::fake();
        $this->companySettings();

        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));
        app(ElectronicBillingService::class)->queueSend($ei);

        $this->assertSame(ElectronicInvoice::STATUS_QUEUED, $ei->fresh()->status);

        (new SendElectronicInvoiceJob($ei->id))->failed(new \RuntimeException('token inválido'));

        $ei->refresh();
        $this->assertSame(ElectronicInvoice::STATUS_ERROR, $ei->status);
        $this->assertStringContainsString('token inválido', $ei->error_message);
    }

    public function test_un_comprobante_borrado_no_tumba_al_worker(): void
    {
        $this->companySettings();

        // No debe lanzar: el job registra y sale.
        (new SendElectronicInvoiceJob(999999))->handle(app(ElectronicBillingService::class));

        $this->assertTrue(true);
    }

    /**
     * Sin emisor_data el payload viaja con numeroIdentificacion vacío: Hacienda
     * responde 400 y el consecutivo ya quedó quemado.
     */
    public function test_no_se_transmite_un_comprobante_sin_datos_del_emisor(): void
    {
        $this->companySettings();
        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        $ei->forceFill(['emisor_data' => null])->save();

        $blocker = app(ElectronicBillingService::class)->sendBlocker($ei->fresh());

        $this->assertNotNull($blocker);
        $this->assertStringContainsString('datos del emisor', $blocker);
    }

    /** La cédula del emisor va dentro de la clave: si no cuadra, jamás se acepta. */
    public function test_no_se_transmite_si_la_cedula_del_emisor_no_coincide(): void
    {
        $this->companySettings();
        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        $emisor = $ei->emisor_data;
        $emisor['identification_number'] = '3101999999';
        $ei->forceFill(['emisor_data' => $emisor])->save();

        $blocker = app(ElectronicBillingService::class)->sendBlocker($ei->fresh());

        $this->assertNotNull($blocker);
        $this->assertStringContainsString('no coincide', $blocker);
    }
}
