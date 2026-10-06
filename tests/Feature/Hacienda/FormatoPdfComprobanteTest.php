<?php

namespace Tests\Feature\Hacienda;

use App\Models\Customer;
use App\Models\ElectronicInvoice;
use App\Models\User;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use App\Services\Hacienda\PdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El PDF del comprobante en el formato que reciben los clientes: emisor con QR,
 * receptor y documento lado a lado, detalle con IVA por línea, desglose del
 * impuesto, totales, clave numérica y la resolución que lo autoriza.
 *
 * Se arma con el XML que produce el sistema de verdad, no con uno escrito a
 * mano: lo que se prueba es lo que va a leer el PDF en producción.
 */
class FormatoPdfComprobanteTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private ElectronicInvoice $comprobante;
    private string $xml;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Storage::fake('hacienda');

        $this->companySettings();
        $guia = $this->deliveredInvoice($this->branch(), ['recipient_phone' => '8820-1425']);

        $this->comprobante = app(ElectronicBillingService::class)->queueForInvoice($guia);
        $this->xml = (new FacturaElectronicaXml($this->comprobante))->build();
    }

    private function html(): string
    {
        return view('pdf.comprobante-electronico', app(PdfGenerator::class)->datos($this->xml, $this->comprobante))->render();
    }

    public function test_lleva_el_emisor_con_cedula_telefono_correo_y_direccion(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Encomiendas de Prueba S.A.', $html);
        $this->assertStringContainsString('Encomiendas CR', $html);
        $this->assertStringContainsString('Céd: 3101123456', $html);
        $this->assertStringContainsString('Tel: 22001100', $html);
        $this->assertStringContainsString('facturacion@encomiendas.test', $html);
        // La provincia por nombre, no el código del catálogo.
        $this->assertStringContainsString('San José. Oficentro, piso 2', $html);
    }

    public function test_lleva_el_cliente_y_los_datos_del_documento(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('José Fernández', $html);
        $this->assertStringContainsString('112340567', $html);
        $this->assertStringContainsString('jose@cliente.test', $html);
        $this->assertStringContainsString('8820-1425', $html, 'El teléfono sale de la guía cuando no hay cliente registrado.');
        $this->assertStringContainsString('Factura Electrónica', $html);
        $this->assertStringContainsString($this->comprobante->consecutivo, $html);
        $this->assertStringContainsString('Contado', $html);
        $this->assertStringContainsString('Efectivo', $html);
        $this->assertStringContainsString('CRC', $html);
        $this->assertStringContainsString('4923.0', $html);
    }

    public function test_la_direccion_del_cliente_sale_de_su_ficha(): void
    {
        Customer::create([
            'name' => 'José Fernández', 'identification' => '112340567', 'identification_type' => '01',
            'phone' => '88201425', 'address' => 'Heredia, San Joaquín', 'payment_condition' => Customer::PAYMENT_CASH,
        ]);

        $html = $this->html();

        $this->assertStringContainsString('Heredia, San Joaquín', $html);
        $this->assertStringContainsString('88201425', $html);
    }

    public function test_el_detalle_lleva_iva_por_linea_con_montos_a_la_costarricense(): void
    {
        $html = $this->html();

        foreach (['Código', 'Nombre', 'Unidad', 'Cantidad', 'Precio', 'Subtotal', 'Descuento', '% IVA', 'Impuesto', 'Total'] as $columna) {
            $this->assertStringContainsString($columna, $html);
        }

        $this->assertStringContainsString('Servicio', $html, 'La unidad Sp se imprime con nombre.');
        $this->assertStringContainsString('6.000,00', $html);
        $this->assertStringContainsString('13,00', $html);
        $this->assertStringContainsString('780,00', $html, 'IVA de la línea de 6 000.');
    }

    public function test_desglose_del_iva_y_totales(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Monto impuesto', $html);
        $this->assertStringContainsString('Monto exonerado', $html);
        $this->assertStringContainsString('1.300,00', $html);
        $this->assertStringContainsString('₡11.300,00', $html);
        foreach (['- Descuento', '+ Impuesto', '+ Cargo Consumo', '- IVA Devuelto', 'TOTAL', 'Exonerado'] as $rotulo) {
            $this->assertStringContainsString($rotulo, $html);
        }
    }

    public function test_lleva_la_guia_la_clave_numerica_y_la_resolucion(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Guía: ENC-000001', $html);
        $this->assertStringContainsString('Emisor: Marta Solano', $html);
        $this->assertStringContainsString('Receptor: José Fernández', $html);
        $this->assertStringContainsString('Clave numérica', $html);
        $this->assertStringContainsString($this->comprobante->clave, $html);
        $this->assertStringContainsString('Afecta Documento', $html);
        $this->assertStringContainsString('Autorizado por MH-DGT-RES-0027-2024', $html);
    }

    /** El QR va como imagen incrustada: DomPDF no sale a la red a buscarla. */
    public function test_lleva_el_qr_incrustado(): void
    {
        $datos = app(PdfGenerator::class)->datos($this->xml, $this->comprobante);

        $this->assertStringStartsWith('data:image/png;base64,', $datos['qr']);
        $this->assertStringContainsString($datos['qr'], $this->html());
    }

    /** Una nota dice a qué comprobante afecta y por qué. */
    public function test_una_nota_dice_que_documento_afecta(): void
    {
        $this->comprobante = $this->markAccepted($this->comprobante);
        $nota = app(ElectronicBillingService::class)->issueNote($this->comprobante, 'NC', 'Cobro duplicado', 1130);
        $xml = (new \App\Services\Hacienda\NotaCreditoXml($nota))->build();

        $html = view('pdf.comprobante-electronico', app(PdfGenerator::class)->datos($xml, $nota))->render();

        $this->assertStringContainsString('Nota de Crédito', $html);
        $this->assertStringContainsString('Factura electrónica ' . $this->comprobante->clave, $html);
        $this->assertStringContainsString('Cobro duplicado', $html);
    }

    /** Se genera el PDF de verdad y queda guardado. */
    public function test_genera_y_guarda_el_pdf(): void
    {
        Storage::disk('hacienda')->put('firmados/x.xml', $this->xml);
        $this->comprobante->forceFill(['signed_xml_path' => 'firmados/x.xml'])->save();

        $ruta = app(PdfGenerator::class)->generate($this->comprobante);

        $this->assertStringStartsWith('%PDF', Storage::disk('hacienda')->get($ruta));
    }

    /**
     * Ver el PDF lo rehace desde el XML: los comprobantes emitidos antes del
     * formato nuevo también salen con él.
     */
    public function test_ver_el_pdf_lo_rehace_con_el_formato_vigente(): void
    {
        Storage::disk('hacienda')->put('firmados/x.xml', $this->xml);
        Storage::disk('hacienda')->put('pdf/viejo.pdf', '%PDF-1.4 formato viejo');
        $this->comprobante->forceFill(['signed_xml_path' => 'firmados/x.xml', 'pdf_path' => 'pdf/viejo.pdf'])->save();

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $respuesta = $this->actingAs($admin)->get(route('electronic-invoices.pdf', $this->comprobante))->assertOk();

        $this->assertNotSame('%PDF-1.4 formato viejo', $respuesta->getContent());
    }
}
