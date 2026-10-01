<?php

namespace Tests\Feature\Hacienda;

use App\Models\Invoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La Factura Electrónica no siempre va a nombre del destinatario: puede
 * facturarse al remitente o a un tercero, y eso tiene que llegar al XML.
 */
class FacturarAQuienTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function xmlPara(array $extra): string
    {
        $this->companySettings();

        $invoice = $this->deliveredInvoice($this->branch(), array_merge(['bill_type' => Invoice::BILL_INVOICE], $extra));
        $ei = app(ElectronicBillingService::class)->queueForInvoice($invoice);

        return (new FacturaElectronicaXml($ei->fresh()))->build();
    }

    public function test_por_defecto_se_factura_al_destinatario(): void
    {
        $xml = $this->xmlPara([]);

        $this->assertStringContainsString('112340567', $xml);
    }

    public function test_se_puede_facturar_al_remitente(): void
    {
        $xml = $this->xmlPara([
            'bill_to' => Invoice::BILL_TO_SENDER,
            'sender_name' => 'Distribuidora Solano S.A.',
            'sender_identification_type' => '02',
            'sender_identification' => '3101123456',
            'sender_email' => 'facturas@solano.test',
        ]);

        $this->assertStringContainsString('Distribuidora Solano S.A.', $xml);
        $this->assertStringContainsString('3101123456', $xml);
        $this->assertStringContainsString('facturas@solano.test', $xml);
        $this->assertStringNotContainsString('112340567', $xml);
    }

    public function test_se_puede_facturar_a_otra_persona(): void
    {
        $xml = $this->xmlPara([
            'bill_to' => Invoice::BILL_TO_OTHER,
            'billing_name' => 'Importadora Tercera S.A.',
            'billing_identification_type' => '02',
            'billing_identification' => '3101999888',
            'billing_email' => 'cxp@tercera.test',
        ]);

        $this->assertStringContainsString('Importadora Tercera S.A.', $xml);
        $this->assertStringContainsString('3101999888', $xml);
        $this->assertStringNotContainsString('112340567', $xml);
    }

    public function test_facturar_al_remitente_sin_su_cedula_cae_a_tiquete(): void
    {
        $this->companySettings();

        $invoice = $this->deliveredInvoice($this->branch(), [
            'bill_type' => Invoice::BILL_INVOICE,
            'bill_to' => Invoice::BILL_TO_SENDER,
            'sender_identification' => null,
        ]);

        $this->assertFalse($invoice->fresh()->receptorIdentificado());
    }
}
