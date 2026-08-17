<?php

namespace Tests\Feature\Hacienda;

use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * emisor_data se congela al crear el comprobante, que es lo correcto para uno
 * aceptado. Pero si el rechazo fue POR los datos del emisor, corregirlos en la
 * configuración no servía de nada: el reintento seguía enviando los viejos.
 */
class ReintentoActualizaDatosTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function comprobanteRechazado(): ElectronicInvoice
    {
        $this->companySettings([
            'activity_code' => '532000',
            'province' => '1', 'canton' => '01', 'district' => '01',
        ]);

        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        $ei->forceFill(['status' => ElectronicInvoice::STATUS_REJECTED])->save();

        return $ei->fresh();
    }

    public function test_el_reintento_toma_la_configuracion_corregida(): void
    {
        Bus::fake();
        $ei = $this->comprobanteRechazado();

        $this->assertSame('532000', $ei->emisor_data['activity_code']);

        // El usuario corrige lo que Hacienda reclamó (-408 y -37).
        CompanySetting::instance()->forceFill([
            'activity_code' => '492300',
            'canton' => '08', 'district' => '02',
        ])->save();

        app(ElectronicBillingService::class)->retry($ei);
        $ei->refresh();

        $this->assertSame('492300', $ei->emisor_data['activity_code']);
        $this->assertSame('08', $ei->emisor_data['canton']);
        $this->assertSame('02', $ei->emisor_data['district']);
    }

    public function test_el_xml_del_reintento_sale_con_los_datos_nuevos(): void
    {
        Bus::fake();
        $ei = $this->comprobanteRechazado();

        CompanySetting::instance()->forceFill([
            'activity_code' => '492300',
            'canton' => '08', 'district' => '02',
        ])->save();

        app(ElectronicBillingService::class)->retry($ei);

        $xml = (new FacturaElectronicaXml($ei->fresh()))->build();

        $this->assertStringContainsString('<CodigoActividadEmisor>492300</CodigoActividadEmisor>', $xml);
        $this->assertStringContainsString('<Canton>08</Canton>', $xml);
        $this->assertStringContainsString('<Distrito>02</Distrito>', $xml);
        $this->assertStringNotContainsString('532000', $xml);
    }

    public function test_el_reintento_tambien_toma_el_cabys_corregido(): void
    {
        Bus::fake();
        $ei = $this->comprobanteRechazado();

        CompanySetting::instance()->forceFill(['default_cabys_code' => '6512300000000'])->save();

        app(ElectronicBillingService::class)->retry($ei);

        $xml = (new FacturaElectronicaXml($ei->fresh()))->build();

        $this->assertStringContainsString('<CodigoCABYS>6512300000000</CodigoCABYS>', $xml);
        $this->assertStringContainsString('<TotalServGravados>', $xml);
    }
}
