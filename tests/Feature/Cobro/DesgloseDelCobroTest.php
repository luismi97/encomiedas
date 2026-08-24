<?php

namespace Tests\Feature\Cobro;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\Hacienda\XmlBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Hacienda\BuildsHaciendaFixtures;
use Tests\TestCase;

/**
 * El cliente ve por qué paga cada cosa.
 *
 * El seguro y la entrega a domicilio se sumaban al total sin aparecer en
 * ningún lado: el recibo mostraba un número mayor que la suma de los bultos y
 * nadie podía explicar la diferencia. Peor: tampoco iban en el comprobante
 * electrónico, así que el total del XML no cuadraba con el de la guía.
 */
class DesgloseDelCobroTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private Invoice $guia;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        Tax::create(['name'=>'IVA','percent'=>13,'hacienda_code'=>'08','is_default'=>true,'is_active'=>true]);

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);

        // 10.000 de bultos + 7.000 de seguro + 3.000 de domicilio = 20.000 · +13%
        $this->guia = Invoice::create([
            'status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$sj->id,'delivery_branch_id'=>$lim->id,
            'sender_name'=>'Marta','recipient_name'=>'José',
            'declared_value'=>100000,'insurance_fee'=>7000,
            'home_delivery'=>true,'delivery_address'=>'Cieneguita, 200m sur','home_delivery_fee'=>3000,
            'subtotal'=>10000,'discount_amount'=>0,'tax_total'=>2600,'total'=>22600,
            'created_by'=>$this->admin->id,
        ])->fresh();

        $this->guia->items()->create(['description'=>'Caja','price'=>10000]);
    }

    // ── El recibo del cliente ─────────────────────────────────────────

    public function test_el_recibo_desglosa_seguro_y_domicilio(): void
    {
        $this->actingAs($this->admin)
            ->get(route('invoices.recibo', $this->guia))
            ->assertOk()
            ->assertSee('Bultos')
            ->assertSee('Seguro')
            ->assertSee('7,000.00')
            ->assertSee('Entrega a domicilio')
            ->assertSee('3,000.00')
            ->assertSee('22,600.00');
    }

    public function test_el_recibo_muestra_la_direccion_de_entrega(): void
    {
        $this->actingAs($this->admin)
            ->get(route('invoices.recibo', $this->guia))
            ->assertSee('Cieneguita, 200m sur');
    }

    /** Una guía normal no muestra líneas de cargos que no tiene. */
    public function test_una_guia_sin_cargos_no_muestra_lineas_vacias(): void
    {
        $simple = Invoice::create([
            'status'=>Invoice::STATUS_PENDING,
            'pickup_branch_id'=>$this->guia->pickup_branch_id,
            'delivery_branch_id'=>$this->guia->delivery_branch_id,
            'sender_name'=>'M','recipient_name'=>'J',
            'subtotal'=>5000,'discount_amount'=>0,'tax_total'=>650,'total'=>5650,
            'created_by'=>$this->admin->id,
        ])->fresh();

        $this->actingAs($this->admin)
            ->get(route('invoices.recibo', $simple))
            ->assertDontSee('Seguro')
            ->assertDontSee('Entrega a domicilio');
    }

    // ── La factura en PDF ─────────────────────────────────────────────

    public function test_el_pdf_desglosa_los_cargos(): void
    {
        $html = view('pdf.invoice', [
            'invoice' => $this->guia->load(['items', 'taxes', 'pickupBranch', 'deliveryBranch']),
            'company' => CompanySetting::instance(),
        ])->render();

        $this->assertStringContainsString('Seguro sobre valor declarado', $html);
        $this->assertStringContainsString('7,000.00', $html);
        $this->assertStringContainsString('Entrega a domicilio', $html);
        $this->assertStringContainsString('3,000.00', $html);
    }

    // ── El comprobante electrónico ────────────────────────────────

    /**
     * Lo más grave: los cargos no iban al XML, así que el total del
     * comprobante era menor que el de la guía. Hacienda lo rechaza por
     * descuadre, o acepta un documento por menos de lo que el cliente pagó.
     */
    public function test_el_xml_incluye_los_cargos_como_lineas(): void
    {
        $xml = $this->xmlDelComprobante();

        $this->assertStringContainsString('Seguro sobre valor declarado', $xml);
        $this->assertStringContainsString('Entrega a domicilio', $xml);
    }

    public function test_la_suma_de_las_lineas_cuadra_con_la_guia(): void
    {
        $doc = simplexml_load_string($this->xmlDelComprobante());

        $suma = 0.0;
        foreach ($doc->DetalleServicio->LineaDetalle as $linea) {
            $suma += (float) $linea->SubTotal;
        }

        $this->assertEqualsWithDelta(
            20000.0, // 10.000 de bultos + 7.000 de seguro + 3.000 de domicilio
            $suma,
            0.01,
            'El comprobante tiene que cobrar lo mismo que la guía.'
        );
    }

    public function test_hay_una_linea_por_cargo(): void
    {
        $doc = simplexml_load_string($this->xmlDelComprobante());

        $this->assertCount(3, $doc->DetalleServicio->LineaDetalle,
            'Un bulto, el seguro y el domicilio.');
    }

    public function test_sin_cargos_solo_van_los_bultos(): void
    {
        $this->guia->forceFill(['insurance_fee' => 0, 'home_delivery_fee' => 0])->save();

        $doc = simplexml_load_string($this->xmlDelComprobante());

        $this->assertCount(1, $doc->DetalleServicio->LineaDetalle);
    }

    private function xmlDelComprobante(): string
    {
        // El helper de Hacienda deja la configuración completa: sin cédula,
        // certificado y credenciales, queueForInvoice() devuelve null.
        $this->companySettings();

        $comprobante = app(\App\Services\Hacienda\ElectronicBillingService::class)
            ->queueForInvoice($this->guia->fresh());

        return (new \App\Services\Hacienda\TiqueteElectronicoXml($comprobante->fresh()))->build();
    }
}
