<?php

namespace Tests\Feature\Hacienda;

use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use SimpleXMLElement;
use Tests\TestCase;

class XmlBuilderTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function xmlFor(array $invoiceOverrides = []): SimpleXMLElement
    {
        Bus::fake();
        $this->companySettings();
        $invoice = $this->deliveredInvoice($this->branch(), $invoiceOverrides);

        $electronicInvoice = app(ElectronicBillingService::class)->queueForInvoice($invoice);
        $xml = (new FacturaElectronicaXml($electronicInvoice))->build();

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed, 'El XML generado no es válido.');

        return $parsed;
    }

    public function test_la_factura_declara_el_esquema_44_y_los_datos_del_emisor(): void
    {
        $xml = $this->xmlFor();

        $this->assertSame('FacturaElectronica', $xml->getName());
        $this->assertSame(
            'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica',
            (string) $xml->attributes()->xmlns ?: current($xml->getDocNamespaces())
        );
        $this->assertMatchesRegularExpression('/^\d{50}$/', (string) $xml->Clave);
        $this->assertSame('3101123456', (string) $xml->Emisor->Identificacion->Numero);
        $this->assertSame('112340567', (string) $xml->Receptor->Identificacion->Numero);
    }

    public function test_los_totales_del_resumen_cuadran_entre_si(): void
    {
        $xml = $this->xmlFor();
        $resumen = $xml->ResumenFactura;

        $ventaNeta = (float) $resumen->TotalVentaNeta;
        $impuesto  = (float) $resumen->TotalImpuesto;
        $total     = (float) $resumen->TotalComprobante;

        $this->assertEqualsWithDelta($ventaNeta + $impuesto, $total, 0.00001);
        $this->assertEqualsWithDelta(10000.0, $ventaNeta, 0.00001);
        $this->assertEqualsWithDelta(1300.0, $impuesto, 0.00001);
        $this->assertEqualsWithDelta(11300.0, $total, 0.00001);
    }

    public function test_la_suma_de_los_medios_de_pago_iguala_el_total(): void
    {
        $xml = $this->xmlFor();

        $sumaMedios = 0.0;
        foreach ($xml->ResumenFactura->MedioPago as $medio) {
            $sumaMedios += (float) $medio->TotalMedioPago;
        }

        $this->assertEqualsWithDelta((float) $xml->ResumenFactura->TotalComprobante, $sumaMedios, 0.00001);
    }

    public function test_el_medio_de_pago_refleja_el_de_la_encomienda_y_no_efectivo_fijo(): void
    {
        $xml = $this->xmlFor(['payment_method' => 'card']);

        $this->assertSame('02', (string) $xml->ResumenFactura->MedioPago->TipoMedioPago);

        $sinpe = $this->xmlFor(['payment_method' => 'sinpe', 'code' => 'ENC-000002']);
        $this->assertSame('06', (string) $sinpe->ResumenFactura->MedioPago->TipoMedioPago);
    }

    public function test_cada_paquete_es_una_linea_de_detalle_que_cuadra(): void
    {
        $xml = $this->xmlFor();
        $lineas = $xml->DetalleServicio->LineaDetalle;

        $this->assertCount(2, $lineas);

        foreach ($lineas as $linea) {
            $subTotal = (float) $linea->SubTotal;
            $iva      = (float) $linea->Impuesto->Monto;
            $this->assertEqualsWithDelta($subTotal + $iva, (float) $linea->MontoTotalLinea, 0.00001);
            $this->assertSame('8511200000000', (string) $linea->CodigoCABYS);
        }
    }
}
