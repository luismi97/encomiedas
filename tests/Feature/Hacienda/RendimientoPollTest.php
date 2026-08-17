<?php

namespace Tests\Feature\Hacienda;

use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * hacienda:poll corre cada minuto. Sin techo, una acumulación de comprobantes
 * atascados deja un proceso PHP consultando sin parar, minuto tras minuto.
 */
class RendimientoPollTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function comprobantesEnviados(int $cantidad): void
    {
        $this->companySettings();
        $branch = $this->branch();

        for ($i = 1; $i <= $cantidad; $i++) {
            $invoice = $this->deliveredInvoice($branch, ['code' => 'ENC-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
            $ei = app(ElectronicBillingService::class)->queueForInvoice($invoice);
            $ei->forceFill(['status' => ElectronicInvoice::STATUS_SENT])->save();
        }
    }

    private function fakeHacienda(): void
    {
        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'tok'], 200),
            '*recepcion*' => Http::response(['ind-estado' => 'procesando'], 200),
        ]);
    }

    public function test_no_consulta_mas_del_lote_configurado(): void
    {
        $this->comprobantesEnviados(8);
        $this->fakeHacienda();

        $this->artisan('hacienda:poll', ['--limit' => 3])
            ->expectsOutputToContain('Consultando 3 de 8')
            ->assertSuccessful();

        Http::assertSentCount(4); // 1 token + 3 consultas
    }

    /** Los menos consultados primero: ninguno se queda sin turno. */
    public function test_atiende_primero_a_los_mas_atrasados(): void
    {
        $this->comprobantesEnviados(3);
        $this->fakeHacienda();

        $viejo = ElectronicInvoice::orderBy('id')->first();
        $viejo->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $this->artisan('hacienda:poll', ['--limit' => 1])->assertSuccessful();

        // Al consultarlo, su updated_at se movió al presente.
        $this->assertTrue($viejo->fresh()->updated_at->gt(now()->subMinute()));
    }

    /**
     * Sin facturación electrónica configurada no hay nada que consultar, y esto
     * corre cada minuto en toda instalación.
     */
    public function test_sin_configuracion_sale_sin_tocar_la_red(): void
    {
        $this->comprobantesEnviados(3);
        CompanySetting::instance()->forceFill(['enabled' => false])->save();

        Http::fake();

        $this->artisan('hacienda:poll')
            ->expectsOutputToContain('no configurada')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_sin_pendientes_no_consulta_nada(): void
    {
        $this->companySettings();
        Http::fake();

        $this->artisan('hacienda:poll')
            ->expectsOutputToContain('No hay comprobantes en proceso')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    /** El listado no puede hacer una consulta por fila para cargar la encomienda. */
    public function test_carga_las_encomiendas_de_una_sola_vez(): void
    {
        $this->comprobantesEnviados(5);
        $this->fakeHacienda();

        DB::enableQueryLog();
        $this->artisan('hacienda:poll')->assertSuccessful();
        $consultas = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from `invoices`'))
            ->count();
        DB::disableQueryLog();

        // Una para el eager load, no una por comprobante.
        $this->assertLessThan(5, $consultas, 'Hay N+1 cargando las encomiendas del poll.');
    }
}
