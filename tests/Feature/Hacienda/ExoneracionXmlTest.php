<?php

namespace Tests\Feature\Hacienda;

use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use App\Services\Hacienda\NotaCreditoXml;
use App\Services\Hacienda\TiqueteElectronicoXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * Un cliente exonerado no paga el IVA, pero el servicio sigue gravado: la
 * factura declara el 13 % en Impuesto/Monto, lo que se exonera en el nodo
 * Exoneracion y lo cobrado en ImpuestoNeto. El resumen separa lo exonerado de
 * lo gravado, y TotalImpuesto suma solo lo cobrado.
 */
class ExoneracionXmlTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function exoneracion(array $cambios = []): array
    {
        return array_merge([
            'tipo'             => '04',
            'tipo_otro'        => null,
            'numero'           => 'AL-00012345-25',
            'institucion'      => '01',
            'institucion_otro' => null,
            'articulo'         => null,
            'inciso'           => null,
            'fecha_emision'    => '2025-01-02',
            'vence'            => '2027-01-02',
            'tarifa'           => 13.0,
            'identificacion'   => '112340567',
            'cabys'            => [],
        ], $cambios);
    }

    private function comprobante(array $exoneracion, array $guia = []): ElectronicInvoice
    {
        Bus::fake();
        $this->companySettings();

        $invoice = $this->deliveredInvoice($this->branch(), array_merge([
            'tax_exempt'        => true,
            'exemption'         => $exoneracion,
            'exempt_tax_amount' => 1300,
            'tax_total'         => 0,
            'total'             => 10000,
        ], $guia));

        return app(ElectronicBillingService::class)->queueForInvoice($invoice)->fresh();
    }

    private function parse(string $xml): SimpleXMLElement
    {
        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed, 'El XML generado no es válido.');

        return $parsed;
    }

    public function test_la_exoneracion_completa_declara_el_iva_y_no_lo_cobra(): void
    {
        $xml = $this->parse((new FacturaElectronicaXml($this->comprobante($this->exoneracion())))->build());

        $linea = $xml->DetalleServicio->LineaDetalle[0];
        $this->assertSame('08', (string) $linea->Impuesto->CodigoTarifaIVA);
        $this->assertSame('13.00', (string) $linea->Impuesto->Tarifa);
        $this->assertSame('780.00', (string) $linea->Impuesto->Monto);

        $exo = $linea->Impuesto->Exoneracion;
        $this->assertSame('04', (string) $exo->TipoDocumentoEX1);
        $this->assertSame('AL-00012345-25', (string) $exo->NumeroDocumento);
        $this->assertSame('01', (string) $exo->NombreInstitucion);
        $this->assertSame('2025-01-02T00:00:00-06:00', (string) $exo->FechaEmisionEX);
        $this->assertSame('13.00', (string) $exo->TarifaExonerada);
        $this->assertSame('780.00', (string) $exo->MontoExoneracion);

        $this->assertSame('0.00', (string) $linea->ImpuestoNeto);
        $this->assertEqualsWithDelta(6000.0, (float) $linea->MontoTotalLinea, 0.00001);

        $r = $xml->ResumenFactura;
        $this->assertEqualsWithDelta(10000.0, (float) $r->TotalServExonerado, 0.00001);
        $this->assertEqualsWithDelta(10000.0, (float) $r->TotalExonerado, 0.00001);
        $this->assertCount(0, $r->TotalServGravados);
        $this->assertCount(0, $r->TotalGravado);
        $this->assertEqualsWithDelta(10000.0, (float) $r->TotalVenta, 0.00001);
        $this->assertEqualsWithDelta(0.0, (float) $r->TotalImpuesto, 0.00001);
        $this->assertEqualsWithDelta(10000.0, (float) $r->TotalComprobante, 0.00001);
        // El desglose cuadra con TotalImpuesto: lo cobrado, cero.
        $this->assertSame('0.00', (string) $r->TotalDesgloseImpuesto->TotalMontoImpuesto);
        $this->assertEqualsWithDelta(10000.0, (float) $r->MedioPago->TotalMedioPago, 0.00001);
    }

    /** El orden de ExoneracionType es el del XSD: fuera de orden, rechazo. */
    public function test_el_nodo_sigue_el_orden_del_esquema(): void
    {
        $xml = $this->parse((new FacturaElectronicaXml($this->comprobante($this->exoneracion([
            'tipo' => '99', 'tipo_otro' => 'Convenio especial', 'articulo' => 11, 'inciso' => 2,
            'institucion' => '99', 'institucion_otro' => 'Junta de Educación',
        ]))))->build());

        $hijos = [];
        foreach ($xml->DetalleServicio->LineaDetalle[0]->Impuesto->Exoneracion->children() as $hijo) {
            $hijos[] = $hijo->getName();
        }

        $this->assertSame([
            'TipoDocumentoEX1', 'TipoDocumentoOTRO', 'NumeroDocumento', 'Articulo', 'Inciso',
            'NombreInstitucion', 'NombreInstitucionOtros', 'FechaEmisionEX', 'TarifaExonerada', 'MontoExoneracion',
        ], $hijos);

        // Exoneracion va dentro de Impuesto, justo después de Monto.
        $impuesto = [];
        foreach ($xml->DetalleServicio->LineaDetalle[0]->Impuesto->children() as $hijo) {
            $impuesto[] = $hijo->getName();
        }
        $this->assertSame(['Codigo', 'CodigoTarifaIVA', 'Tarifa', 'Monto', 'Exoneracion'], $impuesto);
    }

    /** Con 4 de 13 puntos exonerados se cobran 9 y el resumen se reparte. */
    public function test_la_exoneracion_parcial_cobra_el_resto(): void
    {
        $xml = $this->parse((new FacturaElectronicaXml($this->comprobante(
            $this->exoneracion(['tarifa' => 4.0]),
            ['exempt_tax_amount' => 400, 'tax_total' => 900, 'total' => 10900]
        )))->build());

        $linea = $xml->DetalleServicio->LineaDetalle[0];
        $this->assertSame('780.00', (string) $linea->Impuesto->Monto);
        $this->assertSame('240.00', (string) $linea->Impuesto->Exoneracion->MontoExoneracion);
        $this->assertSame('540.00', (string) $linea->ImpuestoNeto);

        $r = $xml->ResumenFactura;
        $this->assertEqualsWithDelta(10000.0, (float) $r->TotalGravado + (float) $r->TotalExonerado, 0.0001);
        $this->assertEqualsWithDelta(10000 * 4 / 13, (float) $r->TotalExonerado, 0.0001);
        $this->assertEqualsWithDelta(900.0, (float) $r->TotalImpuesto, 0.00001);
        $this->assertEqualsWithDelta(10900.0, (float) $r->TotalComprobante, 0.00001);
        $this->assertSame('900.00', (string) $r->TotalDesgloseImpuesto->TotalMontoImpuesto);
    }

    public function test_el_comprobante_guarda_el_total_exonerado(): void
    {
        $comprobante = $this->comprobante($this->exoneracion());
        $this->assertSame('AL-00012345-25', $comprobante->receptor_data['exoneracion']['numero']);

        $builder = new FacturaElectronicaXml($comprobante);
        $builder->build();

        $this->assertEqualsWithDelta(10000.0, $builder->totals()['exonerado'], 0.00001);
        $this->assertEqualsWithDelta(1300.0, $builder->totals()['iva_exonerado'], 0.00001);
    }

    /** Sin receptor no hay contra quién validar la exoneración. */
    public function test_un_tiquete_no_declara_exoneracion(): void
    {
        $comprobante = $this->comprobante($this->exoneracion(), ['bill_type' => Invoice::BILL_TICKET]);
        $xml = (new TiqueteElectronicoXml($comprobante))->build();

        $this->assertStringNotContainsString('<Exoneracion>', $xml);
        $this->assertArrayNotHasKey('exoneracion', $comprobante->receptor_data ?? []);
    }

    public function test_una_guia_normal_no_cambia(): void
    {
        Bus::fake();
        $this->companySettings();
        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($this->deliveredInvoice($this->branch()));
        $xml = $this->parse((new FacturaElectronicaXml($comprobante))->build());

        $this->assertStringNotContainsString('Exoneracion', $xml->asXML());
        $this->assertSame('780.00', (string) $xml->DetalleServicio->LineaDetalle[0]->ImpuestoNeto);
        $this->assertEqualsWithDelta(1300.0, (float) $xml->ResumenFactura->TotalImpuesto, 0.00001);
    }

    /**
     * La nota sobre una factura exonerada hereda la exoneración: devolver
     * los ₡10 000 que pagó el cliente no puede declarar un IVA que nunca se
     * cobró. Antes la tarifa se despejaba de total_tax (0) y caía al 13 %.
     */
    public function test_la_nota_de_credito_de_una_factura_exonerada_hereda_la_exoneracion(): void
    {
        $original = $this->comprobante($this->exoneracion());
        $original->forceFill([
            'status' => ElectronicInvoice::STATUS_ACCEPTED, 'accepted_at' => now(),
            'sub_total' => 10000, 'total_tax' => 0, 'total' => 10000,
        ])->save();

        $nota = app(ElectronicBillingService::class)->issueNote($original->fresh(), 'NC', 'Anulación', 10000);
        $xml = $this->parse((new NotaCreditoXml($nota))->build());

        $linea = $xml->DetalleServicio->LineaDetalle[0];
        $this->assertSame('13.00', (string) $linea->Impuesto->Tarifa);
        $this->assertSame('AL-00012345-25', (string) $linea->Impuesto->Exoneracion->NumeroDocumento);
        $this->assertSame('0.00', (string) $linea->ImpuestoNeto);
        $this->assertEqualsWithDelta(10000.0, (float) $xml->ResumenFactura->TotalExonerado, 0.00001);
        $this->assertEqualsWithDelta(10000.0, (float) $xml->ResumenFactura->TotalComprobante, 0.00001);
    }
}
