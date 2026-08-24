<?php

namespace Tests\Feature\Hacienda;

use App\Models\Branch;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PDF del comprobante electrónico.
 *
 * Se guardaba una sola vez, al aceptarse, y la descarga devolvía 404 pelado si
 * el archivo faltaba —pasa al mover el sitio de hosting sin arrastrar storage/,
 * o si la generación falló en su momento—. Se puede rehacer porque sale del XML
 * firmado, que sí queda guardado.
 */
class PdfDelComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('hacienda');

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);
    }

    private function comprobante(array $attrs = []): ElectronicInvoice
    {
        $sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);

        $guia = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$sj->id,
            'delivery_branch_id'=>$lim->id,'sender_name'=>'M','recipient_name'=>'J',
            'subtotal'=>10000,'discount_amount'=>0,'tax_total'=>0,'total'=>10000,'created_by'=>$this->admin->id]);

        return ElectronicInvoice::create(array_merge([
            'branch_id' => $sj->id,
            'invoice_id' => $guia->id,
            'clave' => '50601082600310123456700100001010000000001100000001',
            'consecutivo' => '00100001010000000001',
            'document_type' => '04',
            'security_code' => '10000001',
            'environment' => 'sandbox',
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'sub_total' => 10000,
            'total_tax' => 0,
            'total_discount' => 0,
            'total_other_charges' => 0,
            'total' => 10000,
            'status' => 'accepted',
            'issued_at' => now(),
            'send_attempts' => 0,
        ], $attrs));
    }

    /** El XML firmado, del que se rehace el PDF. */
    private function xmlFirmado(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <TiqueteElectronico>
          <Clave>50601082600310123456700100001010000000001100000001</Clave>
          <NumeroConsecutivo>00100001010000000001</NumeroConsecutivo>
          <FechaEmision>2026-08-19T10:00:00-06:00</FechaEmision>
          <Emisor><Nombre>Encomiendas CR</Nombre>
            <Identificacion><Tipo>02</Tipo><Numero>3101234567</Numero></Identificacion></Emisor>
          <DetalleServicio><LineaDetalle>
            <NumeroLinea>1</NumeroLinea><Cantidad>1</Cantidad>
            <Detalle>Servicio de encomienda - Caja</Detalle>
            <PrecioUnitario>10000</PrecioUnitario><MontoTotal>10000</MontoTotal>
            <SubTotal>10000</SubTotal><MontoTotalLinea>10000</MontoTotalLinea>
          </LineaDetalle></DetalleServicio>
          <ResumenFactura><TotalComprobante>10000</TotalComprobante></ResumenFactura>
        </TiqueteElectronico>
        XML;
    }

    // ── El caso normal ────────────────────────────────────────────────

    public function test_descarga_el_pdf_guardado(): void
    {
        $c = $this->comprobante(['pdf_path' => 'pdf/2026-08/comprobante.pdf']);
        Storage::disk('hacienda')->put('pdf/2026-08/comprobante.pdf', '%PDF-1.4 contenido');

        $this->actingAs($this->admin)
            ->get(route('electronic-invoices.pdf', $c))
            ->assertOk();
    }

    // ── Lo reportado: el archivo ya no está ───────────────────────────

    /** El bug: 404 pelado cuando el archivo se perdió. */
    public function test_regenera_el_pdf_si_el_archivo_falta(): void
    {
        $c = $this->comprobante([
            'pdf_path' => 'pdf/2026-08/perdido.pdf',
            'signed_xml_path' => 'firmados/comprobante.xml',
        ]);
        Storage::disk('hacienda')->put('firmados/comprobante.xml', $this->xmlFirmado());

        $this->assertFalse(Storage::disk('hacienda')->exists('pdf/2026-08/perdido.pdf'));

        $this->actingAs($this->admin)
            ->get(route('electronic-invoices.pdf', $c))
            ->assertOk();

        $this->assertTrue(Storage::disk('hacienda')->exists($c->fresh()->pdf_path),
            'El PDF rehecho tiene que quedar guardado.');
    }

    /** Nunca se generó, pero el XML está: también se puede rehacer. */
    public function test_genera_el_pdf_si_nunca_existio(): void
    {
        $c = $this->comprobante(['pdf_path' => null, 'signed_xml_path' => 'firmados/comprobante.xml']);
        Storage::disk('hacienda')->put('firmados/comprobante.xml', $this->xmlFirmado());

        $this->actingAs($this->admin)
            ->get(route('electronic-invoices.pdf', $c))
            ->assertOk();

        $this->assertNotNull($c->fresh()->pdf_path);
    }

    // ── Cuando de verdad no se puede ──────────────────────────────────

    /** Sin XML firmado no hay de dónde rehacerlo: ahí sí es 404, con motivo. */
    public function test_sin_xml_firmado_explica_por_que_no_hay_pdf(): void
    {
        $c = $this->comprobante(['pdf_path' => null, 'signed_xml_path' => null, 'status' => 'pending']);

        $this->actingAs($this->admin)
            ->get(route('electronic-invoices.pdf', $c))
            ->assertNotFound();
    }

    public function test_un_xml_registrado_pero_ausente_tampoco_inventa_nada(): void
    {
        $c = $this->comprobante(['pdf_path' => null, 'signed_xml_path' => 'firmados/no-esta.xml']);

        $this->actingAs($this->admin)
            ->get(route('electronic-invoices.pdf', $c))
            ->assertNotFound();
    }

    public function test_hace_falta_iniciar_sesion(): void
    {
        $c = $this->comprobante(['pdf_path' => 'pdf/2026-08/x.pdf']);
        Storage::disk('hacienda')->put('pdf/2026-08/x.pdf', '%PDF-1.4');

        $this->get(route('electronic-invoices.pdf', $c))->assertRedirect(route('login'));
    }
}
