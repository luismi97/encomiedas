<?php

namespace Tests\Feature\Hacienda;

use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Services\Hacienda\Catalogs;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use App\Services\Hacienda\TiqueteElectronicoXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El toggle del formulario tiene que llegar hasta el XML: tipo de documento,
 * consecutivo y presencia del bloque Receptor cambian entre FE y TE.
 */
class TiqueteElectronicoTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function comprobantePara(string $billType, array $extra = []): ElectronicInvoice
    {
        $this->companySettings();

        $invoice = $this->deliveredInvoice($this->branch(), array_merge([
            'bill_type' => $billType,
        ], $extra));

        return app(ElectronicBillingService::class)->queueForInvoice($invoice);
    }

    public function test_sin_factura_marcada_se_reserva_un_tiquete(): void
    {
        $ei = $this->comprobantePara(Invoice::BILL_TICKET, [
            'recipient_identification' => null,
        ]);

        $this->assertSame(Catalogs::documentCode('TE'), $ei->document_type);
        $this->assertSame('04', $ei->document_type);
    }

    public function test_con_factura_marcada_se_reserva_una_factura(): void
    {
        $ei = $this->comprobantePara(Invoice::BILL_INVOICE);

        $this->assertSame(Catalogs::documentCode('FE'), $ei->document_type);
        $this->assertSame('01', $ei->document_type);
    }

    public function test_el_tiquete_no_lleva_bloque_receptor(): void
    {
        $ei = $this->comprobantePara(Invoice::BILL_TICKET, ['recipient_identification' => null]);

        $xml = (new TiqueteElectronicoXml($ei->fresh()))->build();

        $this->assertStringContainsString('tiqueteElectronico', $xml);
        $this->assertStringNotContainsString('<Receptor>', $xml);
    }

    public function test_la_factura_lleva_el_receptor_identificado(): void
    {
        $ei = $this->comprobantePara(Invoice::BILL_INVOICE);

        $xml = (new FacturaElectronicaXml($ei->fresh()))->build();

        $this->assertStringContainsString('facturaElectronica', $xml);
        $this->assertStringContainsString('<Receptor>', $xml);
        $this->assertStringContainsString('112340567', $xml);
    }

    /**
     * El consecutivo lleva el tipo en las posiciones 9-10: un tiquete numera
     * aparte de una factura, cada uno con su propia serie.
     */
    public function test_factura_y_tiquete_numeran_por_separado(): void
    {
        $fe = $this->comprobantePara(Invoice::BILL_INVOICE);

        $te = app(ElectronicBillingService::class)->queueForInvoice(
            $this->deliveredInvoice($this->branch(), [
                'code' => 'ENC-000002',
                'bill_type' => Invoice::BILL_TICKET,
                'recipient_identification' => null,
            ])
        );

        $this->assertSame('01', substr($fe->consecutivo, 8, 2));
        $this->assertSame('04', substr($te->consecutivo, 8, 2));

        // Cada tipo arranca su propia numeración en 1.
        $this->assertSame('0000000001', substr($fe->consecutivo, 10));
        $this->assertSame('0000000001', substr($te->consecutivo, 10));
    }

    public function test_marcar_factura_sin_cedula_cae_a_tiquete_y_no_a_una_fe_invalida(): void
    {
        // Hacienda rechaza una FE sin receptor identificado: ante datos
        // incompletos es preferible el tiquete, que es válido.
        $ei = $this->comprobantePara(Invoice::BILL_INVOICE, ['recipient_identification' => null]);

        $this->assertSame('04', $ei->document_type);
    }
}
