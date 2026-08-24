<?php

namespace Tests\Feature\Guides;

use App\Livewire\Invoices\InvoiceShow;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Firma de quien retira la encomienda.
 *
 * El pad se inicializaba con un script que corría al arrancar el componente,
 * pero el canvas vive dentro de un @if y todavía no existía en el DOM:
 * getElementById devolvía null, el script moría y el pad no dibujaba nada. Al
 * no capturarse nunca una firma, la evidencia del detalle tampoco mostraba
 * ninguna.
 */
class FirmaDeEntregaTest extends TestCase
{
    use RefreshDatabase;

    /** Un PNG mínimo válido, como el que produce el canvas. */
    private const FIRMA = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);
    }

    private function guiaEnDestino(): Invoice
    {
        $guia = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->sj->id,
            'delivery_branch_id'=>$this->lim->id,'sender_name'=>'Marta','recipient_name'=>'José',
            'subtotal'=>1000,'discount_amount'=>0,'tax_total'=>0,'total'=>1000,'created_by'=>$this->admin->id])->fresh();

        $estados = app(GuideStatusService::class);
        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $e) {
            $guia = $estados->cambiar($guia, $e, $this->admin);
        }

        return $guia;
    }

    // ── El pad ────────────────────────────────────────────────────────

    /**
     * Alpine y no @script: x-init corre cuando el elemento entra al DOM, que es
     * al abrirse el formulario. Un script de inicialización no lo alcanza.
     */
    public function test_el_pad_se_inicializa_al_aparecer_y_no_al_cargar(): void
    {
        $guia = $this->guiaEnDestino();

        $html = Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('openDeliveryForm')
            ->html();

        $this->assertStringContainsString('x-data', $html);
        $this->assertStringContainsString('x-ref="lienzo"', $html);
        $this->assertStringContainsString('deliverySignature', $html);
    }

    public function test_el_canvas_no_se_busca_por_id_global(): void
    {
        $pad = file_get_contents(resource_path('views/components/signature-pad.blade.php'));

        $this->assertStringNotContainsString('getElementById', $pad,
            'Buscar el canvas por id falla si todavía no está en el DOM.');
    }

    /** El pad se comparte entre el detalle y el panel del chofer. */
    public function test_las_dos_pantallas_usan_el_mismo_pad(): void
    {
        foreach (['invoices/invoice-show', 'chofer/chofer-panel'] as $vista) {
            $html = file_get_contents(resource_path("views/livewire/{$vista}.blade.php"));

            $this->assertStringContainsString('<x-signature-pad', $html, "Falta el pad en {$vista}.");
            $this->assertStringNotContainsString('getContext', $html,
                "Quedó un pad artesanal en {$vista}.");
        }
    }

    // ── La captura ────────────────────────────────────────────────────

    public function test_la_firma_capturada_se_guarda_con_la_entrega(): void
    {
        $guia = $this->guiaEnDestino();

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->call('openDeliveryForm')
            ->set('receivedByName', 'José Fernández')
            ->set('deliverySignature', self::FIRMA)
            ->call('entregar');

        $guia->refresh();

        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->status);
        $this->assertSame(self::FIRMA, $guia->delivery_signature);
    }

    // ── La evidencia en el detalle ────────────────────────────────────

    public function test_la_evidencia_muestra_la_firma(): void
    {
        $guia = $this->guiaEnDestino();

        app(GuideStatusService::class)->entregar($guia, $this->admin, 'José Fernández', '108880777', self::FIRMA);

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia->fresh()])
            ->assertSee('Evidencia de entrega')
            ->assertSee('José Fernández')
            ->assertSee('108880777')
            ->assertSee(self::FIRMA, false);
    }

    public function test_una_entrega_sin_firma_muestra_el_resto_igual(): void
    {
        $guia = $this->guiaEnDestino();

        app(GuideStatusService::class)->entregar($guia, $this->admin, 'José Fernández', '108880777');

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia->fresh()])
            ->assertSee('Evidencia de entrega')
            ->assertSee('José Fernández')
            ->assertDontSee('<img src="data:image/png', false);
    }

    /** Solo se acepta una imagen: un data URI arbitrario no se guarda. */
    public function test_una_firma_que_no_es_imagen_se_descarta(): void
    {
        $guia = $this->guiaEnDestino();

        app(GuideStatusService::class)->entregar(
            $guia, $this->admin, 'José', null, 'data:text/html;base64,PHNjcmlwdD4='
        );

        $this->assertNull($guia->fresh()->delivery_signature);
    }
}
