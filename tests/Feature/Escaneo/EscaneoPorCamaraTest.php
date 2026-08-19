<?php

namespace Tests\Feature\Escaneo;

use App\Livewire\Chofer\ChoferPanel;
use App\Livewire\Dispatches\DispatchIndex;
use App\Models\Branch;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DispatchService;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Escaneo con la cámara del dispositivo, además del lector físico.
 *
 * El overlay vive en el layout y no dentro del componente a propósito: el morph
 * de Livewire destruiría el <video> en mitad del escaneo y la cámara quedaría
 * encendida sin dueño.
 */
class EscaneoPorCamaraTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        $this->admin = User::create(['name'=>'A','username'=>'a','email'=>'a@t.test','password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);
    }

    private function guia(): Invoice
    {
        return Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->sj->id,
            'delivery_branch_id'=>$this->lim->id,'sender_name'=>'M','recipient_name'=>'J',
            'subtotal'=>1000,'discount_amount'=>0,'tax_total'=>0,'total'=>1000,'created_by'=>$this->admin->id])->fresh();
    }

    private function cierreEnRuta(): array
    {
        $guia = $this->guia();
        app(GuideStatusService::class)->cambiar($guia, Invoice::STATUS_READY, $this->admin);

        // El manifiesto se crea desde el componente: el servicio no tiene un
        // método para armarlo, solo para moverlo.
        $cierre = Dispatch::create([
            'code' => 'CIE-000001',
            'origin_branch_id' => $this->sj->id,
            'destination_branch_id' => $this->lim->id,
            'driver_name' => 'Chofer',
            'created_by' => $this->admin->id,
        ])->fresh();

        $servicio = app(DispatchService::class);
        $servicio->agregarGuia($cierre, $guia);
        $servicio->despachar($cierre, $this->admin);

        return [$cierre->fresh(), $guia->fresh()];
    }

    // ── El overlay ────────────────────────────────────────────────────

    public function test_el_overlay_esta_en_el_layout_y_no_en_el_componente(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('<x-barcode-scanner />', $layout);

        // Si viviera dentro de un componente Livewire, el morph lo destruiría.
        foreach (glob(resource_path('views/livewire/*/*.blade.php')) as $vista) {
            $this->assertStringNotContainsString('scanner-video', file_get_contents($vista),
                'El <video> del escáner no puede vivir dentro de un componente Livewire: '
                . basename($vista));
        }
    }

    public function test_la_pagina_carga_el_lector_y_su_overlay(): void
    {
        [$cierre] = $this->cierreEnRuta();

        $html = $this->actingAs($this->admin)->get(route('dispatches.index'))->getContent();

        $this->assertStringContainsString('id="scanner-overlay"', $html);
        $this->assertStringContainsString('id="scanner-video"', $html);
        $this->assertStringContainsString('js/barcode-scanner.js', $html);
    }

    /** El navegador cachea el script: sin la marca de versión no se actualiza. */
    public function test_el_script_va_versionado(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->getContent();

        $this->assertMatchesRegularExpression('#js/barcode-scanner\.js\?v=\d+#', $html);
    }

    // ── Los botones ───────────────────────────────────────────────────

    public function test_recibir_un_cierre_ofrece_la_camara(): void
    {
        [$cierre] = $this->cierreEnRuta();

        $html = Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('open', $cierre->id)
            ->html();

        $this->assertStringContainsString('EncomiendasScanner.open', $html);
        $this->assertStringContainsString("\$wire.recibirPorCodigo()", $html);
    }

    public function test_el_chofer_tiene_la_camara_en_la_calle(): void
    {
        [$cierre, $guia] = $this->cierreEnRuta();

        $chofer = User::create(['name'=>'R','username'=>'r','email'=>'r@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_REPARTIDOR,'is_active'=>true]);
        $cierre->forceFill(['driver_user_id' => $chofer->id])->save();

        $html = Livewire::actingAs($chofer)
            ->test(ChoferPanel::class)
            ->set('dispatchId', $cierre->id)
            ->html();

        $this->assertStringContainsString('EncomiendasScanner.open', $html);
        $this->assertStringContainsString("\$wire.escanear()", $html);
    }

    /** La cámara deja el código donde el lector físico lo escribiría. */
    public function test_la_camara_escribe_en_el_mismo_campo_que_el_lector(): void
    {
        [$cierre] = $this->cierreEnRuta();

        $html = Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('open', $cierre->id)
            ->html();

        $this->assertStringContainsString("\$wire.set('scanCode', code)", $html);
    }

    /** Lo leído por cámara y lo tecleado siguen el mismo camino en el servidor. */
    public function test_un_codigo_leido_recibe_la_guia_igual_que_a_mano(): void
    {
        [$cierre, $guia] = $this->cierreEnRuta();

        Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('open', $cierre->id)
            ->set('scanCode', $guia->code)
            ->call('recibirPorCodigo');

        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $guia->fresh()->status);
    }

    // ── El lector ─────────────────────────────────────────────────────

    public function test_el_lector_reconoce_el_formato_de_nuestras_etiquetas(): void
    {
        $js = file_get_contents(public_path('js/barcode-scanner.js'));

        // Code 128 es el de la etiqueta del paquete; QR el del recibo.
        $this->assertStringContainsString("'code_128'", $js);
        $this->assertStringContainsString("'qr_code'", $js);
    }

    /** Sin apagar las pistas, la luz de la cámara queda encendida. */
    public function test_al_cerrar_se_apaga_la_camara(): void
    {
        $js = file_get_contents(public_path('js/barcode-scanner.js'));

        $this->assertStringContainsString('getTracks().forEach((t) => t.stop())', $js);
        $this->assertStringContainsString("addEventListener('livewire:navigating'", $js);
        $this->assertStringContainsString("addEventListener('pagehide'", $js);
    }

    public function test_hay_respaldo_para_navegadores_sin_la_api_nativa(): void
    {
        $js = file_get_contents(public_path('js/barcode-scanner.js'));

        $this->assertStringContainsString('BarcodeDetector', $js);
        $this->assertStringContainsString('zxing', strtolower($js));
        $this->assertStringContainsString('HTTPS', $js, 'Tiene que explicar que la cámara exige contexto seguro.');
    }
}
