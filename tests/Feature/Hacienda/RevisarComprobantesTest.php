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
 * Diagnóstico de los archivos de comprobantes.
 *
 * El PDF y el XML viven en storage/app/hacienda, que no viaja con el código: al
 * mover el sitio de hosting quedan atrás y las descargas dan 404 sin decir por
 * qué. Saber cuáles se pueden rehacer y cuáles no es la diferencia entre
 * repararlo y buscar a ciegas.
 */
class RevisarComprobantesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('hacienda');
    }

    private function comprobante(array $attrs = []): ElectronicInvoice
    {
        $sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        $u = User::create(['name'=>'A','username'=>'a','email'=>'a@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_ADMIN,'is_active'=>true]);

        $guia = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$sj->id,
            'delivery_branch_id'=>$lim->id,'sender_name'=>'M','recipient_name'=>'J',
            'subtotal'=>10000,'discount_amount'=>0,'tax_total'=>0,'total'=>10000,'created_by'=>$u->id]);

        return ElectronicInvoice::create(array_merge([
            'branch_id'=>$sj->id,'invoice_id'=>$guia->id,
            'clave'=>'5060108260031012345670010000101000000000110000000'.rand(1,9),
            'consecutivo'=>'0010000101000000000'.rand(1,9),
            'document_type'=>'04','security_code'=>'10000001','environment'=>'sandbox',
            'currency_code'=>'CRC','exchange_rate'=>1,'sub_total'=>10000,'total_tax'=>0,
            'total_discount'=>0,'total_other_charges'=>0,'total'=>10000,
            'status'=>'accepted','issued_at'=>now(),'send_attempts'=>1,
        ], $attrs));
    }

    private function xmlFirmado(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><TiqueteElectronico>'
            . '<Clave>50601082600310123456700100001010000000001100000001</Clave>'
            . '<NumeroConsecutivo>00100001010000000001</NumeroConsecutivo>'
            . '<FechaEmision>2026-08-19T10:00:00-06:00</FechaEmision>'
            . '<Emisor><Nombre>Encomiendas CR</Nombre><Identificacion><Tipo>02</Tipo>'
            . '<Numero>3101234567</Numero></Identificacion></Emisor>'
            . '<DetalleServicio><LineaDetalle><NumeroLinea>1</NumeroLinea><Cantidad>1</Cantidad>'
            . '<Detalle>Encomienda</Detalle><PrecioUnitario>10000</PrecioUnitario>'
            . '<MontoTotal>10000</MontoTotal><SubTotal>10000</SubTotal>'
            . '<MontoTotalLinea>10000</MontoTotalLinea></LineaDetalle></DetalleServicio>'
            . '<ResumenFactura><TotalComprobante>10000</TotalComprobante></ResumenFactura>'
            . '</TiqueteElectronico>';
    }

    public function test_sin_comprobantes_lo_dice(): void
    {
        $this->artisan('hacienda:revisar')
            ->expectsOutputToContain('No hay comprobantes registrados')
            ->assertSuccessful();
    }

    public function test_reporta_el_que_esta_completo(): void
    {
        $c = $this->comprobante(['pdf_path' => 'pdf/x.pdf']);
        Storage::disk('hacienda')->put('pdf/x.pdf', '%PDF');

        $this->artisan('hacienda:revisar')
            ->expectsOutputToContain('completo')
            ->expectsOutputToContain('No hay PDF que regenerar')
            ->assertSuccessful();
    }

    /** El caso reparable: falta el PDF pero está el XML. */
    public function test_detecta_los_pdf_que_se_pueden_rehacer(): void
    {
        $this->comprobante(['pdf_path' => 'pdf/perdido.pdf', 'signed_xml_path' => 'firmados/a.xml']);
        Storage::disk('hacienda')->put('firmados/a.xml', $this->xmlFirmado());

        $this->artisan('hacienda:revisar')
            ->expectsOutputToContain('se pueden regenerar')
            ->expectsOutputToContain('--reparar')
            ->assertSuccessful();
    }

    public function test_con_reparar_los_regenera(): void
    {
        $c = $this->comprobante(['pdf_path' => 'pdf/perdido.pdf', 'signed_xml_path' => 'firmados/a.xml']);
        Storage::disk('hacienda')->put('firmados/a.xml', $this->xmlFirmado());

        $this->artisan('hacienda:revisar', ['--reparar' => true])->assertSuccessful();

        $this->assertTrue(Storage::disk('hacienda')->exists($c->fresh()->pdf_path));
    }

    /** Sin XML no hay de dónde rehacerlo: hay que decirlo, no callarlo. */
    public function test_avisa_de_los_que_no_se_pueden_rehacer(): void
    {
        $this->comprobante(['pdf_path' => 'pdf/perdido.pdf', 'signed_xml_path' => null]);

        $this->artisan('hacienda:revisar')
            ->expectsOutputToContain('no se pueden rehacer')
            ->expectsOutputToContain('storage/app/hacienda')
            ->assertSuccessful();
    }

    public function test_sin_reparar_no_toca_nada(): void
    {
        $c = $this->comprobante(['pdf_path' => 'pdf/perdido.pdf', 'signed_xml_path' => 'firmados/a.xml']);
        Storage::disk('hacienda')->put('firmados/a.xml', $this->xmlFirmado());

        $this->artisan('hacienda:revisar')->assertSuccessful();

        $this->assertFalse(Storage::disk('hacienda')->exists('pdf/perdido.pdf'));
    }

    /** Muestra la ruta real del disco: el problema suele estar ahí. */
    public function test_muestra_donde_busca_los_archivos(): void
    {
        $this->comprobante(['pdf_path' => 'pdf/x.pdf']);

        $this->artisan('hacienda:revisar')
            ->expectsOutputToContain('Disco')
            ->expectsOutputToContain('Ruta')
            ->assertSuccessful();
    }
}
