<?php

namespace Tests\Feature\Hacienda;

use App\Jobs\SendElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\NotaCreditoXml;
use App\Services\Hacienda\NotaDebitoXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

class NotaElectronicaTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private ElectronicBillingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->companySettings();
        $this->service = app(ElectronicBillingService::class);
    }

    private function acceptedInvoice(): ElectronicInvoice
    {
        $invoice = $this->deliveredInvoice($this->branch());

        return $this->markAccepted($this->service->queueForInvoice($invoice));
    }

    public function test_la_nota_de_credito_nace_con_su_propio_consecutivo_tipo_03(): void
    {
        $original = $this->acceptedInvoice();

        $note = $this->service->issueNote($original, 'NC', 'Anulación por encomienda devuelta');

        $this->assertSame('03', $note->document_type);
        $this->assertSame($original->id, $note->reference_invoice_id);
        $this->assertSame($original->invoice_id, $note->invoice_id);
        $this->assertNotSame($original->clave, $note->clave);
        $this->assertSame('03', substr($note->consecutivo, 8, 2));
        $this->assertSame(ElectronicInvoice::STATUS_QUEUED, $note->status);

        Bus::assertDispatched(SendElectronicInvoiceJob::class);
    }

    public function test_el_xml_de_la_nota_referencia_el_comprobante_original_despues_del_resumen(): void
    {
        $original = $this->acceptedInvoice();
        $note = $this->service->issueNote($original, 'NC', 'Anulación por encomienda devuelta');

        $xml = (new NotaCreditoXml($note))->build();
        $parsed = simplexml_load_string($xml);

        $this->assertNotFalse($parsed);
        $this->assertSame('NotaCreditoElectronica', $parsed->getName());

        $referencia = $parsed->InformacionReferencia;
        $this->assertSame('01', (string) $referencia->TipoDocIR);
        $this->assertSame($original->clave, (string) $referencia->Numero);
        $this->assertSame('01', (string) $referencia->Codigo); // 01 = anula
        $this->assertSame('Anulación por encomienda devuelta', (string) $referencia->Razon);

        // El esquema v4.4 exige InformacionReferencia DESPUÉS de ResumenFactura.
        $orden = [];
        foreach ($parsed->children() as $child) {
            $orden[] = $child->getName();
        }
        $this->assertGreaterThan(
            array_search('ResumenFactura', $orden, true),
            array_search('InformacionReferencia', $orden, true),
            'InformacionReferencia debe ir después de ResumenFactura.'
        );
    }

    public function test_la_nota_lleva_montos_positivos_porque_hacienda_rechaza_los_negativos(): void
    {
        $original = $this->acceptedInvoice();
        $note = $this->service->issueNote($original, 'NC', 'Anulación total');

        $xml = simplexml_load_string((new NotaCreditoXml($note))->build());

        $montos = [
            (float) $xml->ResumenFactura->TotalVenta,
            (float) $xml->ResumenFactura->TotalVentaNeta,
            (float) $xml->ResumenFactura->TotalImpuesto,
            (float) $xml->ResumenFactura->TotalComprobante,
            (float) $xml->DetalleServicio->LineaDetalle->MontoTotalLinea,
        ];

        foreach ($montos as $monto) {
            $this->assertGreaterThanOrEqual(0, $monto, 'Ningún monto del comprobante puede ser negativo.');
        }

        // El total de la nota reproduce el del comprobante que anula.
        $this->assertEqualsWithDelta(11300.0, (float) $xml->ResumenFactura->TotalComprobante, 0.01);
        $this->assertEqualsWithDelta(
            (float) $xml->ResumenFactura->TotalVentaNeta + (float) $xml->ResumenFactura->TotalImpuesto,
            (float) $xml->ResumenFactura->TotalComprobante,
            0.00001
        );
    }

    public function test_una_nota_parcial_desglosa_bien_el_iva_incluido(): void
    {
        $original = $this->acceptedInvoice();
        $note = $this->service->issueNote($original, 'NC', 'Corrige monto por descuento aplicado', 2260.0);

        $xml = simplexml_load_string((new NotaCreditoXml($note))->build());

        // 2 260 con IVA incluido = 2 000 de base + 260 de IVA (13 %).
        $this->assertEqualsWithDelta(2000.0, (float) $xml->ResumenFactura->TotalVentaNeta, 0.01);
        $this->assertEqualsWithDelta(260.0, (float) $xml->ResumenFactura->TotalImpuesto, 0.01);
        $this->assertEqualsWithDelta(2260.0, (float) $xml->ResumenFactura->TotalComprobante, 0.01);

        // "corrige monto" tiene que mapear al código 02, no al de anulación.
        $this->assertSame('02', (string) $xml->InformacionReferencia->Codigo);
    }

    public function test_la_nota_de_debito_usa_el_tipo_02(): void
    {
        $original = $this->acceptedInvoice();
        $note = $this->service->issueNote($original, 'ND', 'Cobro adicional por sobrepeso', 5650.0);

        $xml = simplexml_load_string((new NotaDebitoXml($note))->build());

        $this->assertSame('NotaDebitoElectronica', $xml->getName());
        $this->assertSame('02', $note->document_type);
        $this->assertEqualsWithDelta(5650.0, (float) $xml->ResumenFactura->TotalComprobante, 0.01);
    }

    public function test_una_nota_con_lineas_detalladas_arma_una_linea_por_fila(): void
    {
        $original = $this->acceptedInvoice();

        $note = $this->service->issueNote($original, 'NC', 'Corrige monto de dos paquetes', null, [
            ['detalle' => 'Reintegro paquete PKG-001', 'cantidad' => 1, 'precio' => 1130.0],
            ['detalle' => 'Reintegro paquete PKG-002', 'cantidad' => 2, 'precio' => 565.0],
        ]);

        $xml = simplexml_load_string((new NotaCreditoXml($note))->build());
        $lineas = $xml->DetalleServicio->LineaDetalle;

        $this->assertCount(2, $lineas);
        $this->assertSame('Reintegro paquete PKG-001', (string) $lineas[0]->Detalle);
        $this->assertEqualsWithDelta(2.0, (float) $lineas[1]->Cantidad, 0.00001);

        // 1 130 + (565 x 2) = 2 260 con IVA incluido.
        $this->assertEqualsWithDelta(2260.0, (float) $xml->ResumenFactura->TotalComprobante, 0.01);
        $this->assertEqualsWithDelta(
            (float) $xml->ResumenFactura->TotalVentaNeta + (float) $xml->ResumenFactura->TotalImpuesto,
            (float) $xml->ResumenFactura->TotalComprobante,
            0.00001
        );
    }

    public function test_no_se_puede_emitir_una_nota_sobre_un_comprobante_no_aceptado(): void
    {
        $invoice = $this->deliveredInvoice($this->branch());
        $pending = $this->service->queueForInvoice($invoice);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('aceptado por Hacienda');

        $this->service->issueNote($pending, 'NC', 'Anulación');
    }

    public function test_no_se_puede_emitir_una_nota_sobre_otra_nota(): void
    {
        $original = $this->acceptedInvoice();
        $note = $this->markAccepted($this->service->issueNote($original, 'NC', 'Anulación'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sobre otra nota');

        $this->service->issueNote($note, 'NC', 'Anulación de la anulación');
    }

    public function test_una_nota_de_credito_no_puede_exceder_el_total_del_comprobante(): void
    {
        $original = $this->acceptedInvoice();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no puede exceder');

        $this->service->issueNote($original, 'NC', 'Anulación', 99999.0);
    }

    public function test_la_factura_conserva_su_comprobante_aunque_tenga_notas(): void
    {
        $original = $this->acceptedInvoice();
        $note = $this->service->issueNote($original, 'NC', 'Anulación');

        $invoice = $original->invoice->fresh();

        // Sin el filtro por tipo, latestOfMany() devolvería la nota.
        $this->assertSame($original->id, $invoice->electronicInvoice->id);
        $this->assertTrue($invoice->electronicNotes->contains($note->id));
    }
}
