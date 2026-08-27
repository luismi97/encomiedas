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
 * El escaneo suena, y suena distinto según cómo salió.
 *
 * El pitido se emite cuando el SERVIDOR confirma, no cuando la cámara detecta:
 * si sonara al leer, un código ilegible o de otra ruta también pitaría y el
 * operario aprendería que el pitido no significa nada. Con esto, un pitido
 * agudo siempre quiere decir «la guía quedó marcada».
 *
 * Y como el aviso sale del servidor, suena igual con la cámara que con el
 * lector físico: los dos terminan en la misma confirmación.
 */
class SonidoDelEscaneoTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;
    private Dispatch $cierre;
    private Invoice $guia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true,'branch_id'=>$this->lim->id]);

        $this->guia = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->sj->id,
            'delivery_branch_id'=>$this->lim->id,'sender_name'=>'M','recipient_name'=>'J',
            'subtotal'=>1000,'discount_amount'=>0,'tax_total'=>0,'total'=>1000,'created_by'=>$this->admin->id])->fresh();

        app(GuideStatusService::class)->cambiar($this->guia, Invoice::STATUS_READY, $this->admin);

        $this->cierre = Dispatch::create(['code'=>'CIE-000001','origin_branch_id'=>$this->sj->id,
            'destination_branch_id'=>$this->lim->id,'driver_name'=>'Chofer','created_by'=>$this->admin->id])->fresh();

        $servicio = app(DispatchService::class);
        $servicio->agregarGuia($this->cierre, $this->guia->fresh());
        $servicio->despachar($this->cierre, $this->admin);
    }

    private function recepcion()
    {
        return Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('open', $this->cierre->id);
    }

    // ── Recepción de cierres ──────────────────────────────────────────

    public function test_una_guia_recibida_suena_a_exito(): void
    {
        $this->recepcion()
            ->set('scanCode', $this->guia->fresh()->code)
            ->call('recibirPorCodigo')
            ->assertDispatched('scan-resultado', ok: true);
    }

    public function test_un_codigo_inexistente_suena_a_error(): void
    {
        $this->recepcion()
            ->set('scanCode', 'NO-EXISTE-0001')
            ->call('recibirPorCodigo')
            ->assertDispatched('scan-resultado', ok: false);
    }

    /** Una guía de otra ruta se rechaza: tiene que sonar distinto. */
    public function test_una_guia_ajena_al_cierre_suena_a_error(): void
    {
        $ajena = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->lim->id,
            'delivery_branch_id'=>$this->sj->id,'sender_name'=>'M','recipient_name'=>'J',
            'subtotal'=>1000,'discount_amount'=>0,'tax_total'=>0,'total'=>1000,
            'created_by'=>$this->admin->id])->fresh();

        $this->recepcion()
            ->set('scanCode', $ajena->code)
            ->call('recibirPorCodigo')
            ->assertDispatched('scan-resultado', ok: false);
    }

    /** Escanear dos veces la misma tampoco es un éxito nuevo. */
    public function test_repetir_una_guia_ya_recibida_suena_a_error(): void
    {
        $codigo = $this->guia->fresh()->code;

        $this->recepcion()->set('scanCode', $codigo)->call('recibirPorCodigo');

        $this->recepcion()
            ->set('scanCode', $codigo)
            ->call('recibirPorCodigo')
            ->assertDispatched('scan-resultado', ok: false);
    }

    // ── Mi ruta (chofer) ──────────────────────────────────────────────

    public function test_el_chofer_tambien_escucha_el_resultado(): void
    {
        $chofer = User::create(['name'=>'Carlos','username'=>'carlos','email'=>'c@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_REPARTIDOR,'is_active'=>true]);
        $this->cierre->forceFill(['driver_user_id' => $chofer->id])->save();

        Livewire::actingAs($chofer)
            ->test(ChoferPanel::class)
            ->set('dispatchId', $this->cierre->id)
            ->set('scanCode', $this->guia->fresh()->code)
            ->call('escanear')
            ->assertDispatched('scan-resultado', ok: true);
    }

    public function test_el_chofer_con_un_codigo_malo_suena_a_error(): void
    {
        $chofer = User::create(['name'=>'Carlos','username'=>'carlos','email'=>'c@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_REPARTIDOR,'is_active'=>true]);
        $this->cierre->forceFill(['driver_user_id' => $chofer->id])->save();

        Livewire::actingAs($chofer)
            ->test(ChoferPanel::class)
            ->set('dispatchId', $this->cierre->id)
            ->set('scanCode', 'NO-EXISTE')
            ->call('escanear')
            ->assertDispatched('scan-resultado', ok: false);
    }

    // ── El reproductor ────────────────────────────────────────────────

    public function test_la_pagina_carga_los_sonidos(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dispatches.index'))->getContent();

        $this->assertStringContainsString('js/sonidos.js', $html);
        $this->assertMatchesRegularExpression('#js/sonidos\.js\?v=\d+#', $html,
            'Versionado, o el navegador se queda con el archivo viejo.');
    }

    public function test_hay_un_tono_distinto_para_el_error(): void
    {
        $js = file_get_contents(public_path('js/sonidos.js'));

        $this->assertStringContainsString('function ok(', $js);
        $this->assertStringContainsString('function error(', $js);
        $this->assertStringContainsString("addEventListener('scan-resultado'", $js);
    }

    /** El lector ya no pita al detectar: eso lo hace la confirmación. */
    public function test_la_camara_no_pita_por_su_cuenta(): void
    {
        $js = file_get_contents(public_path('js/barcode-scanner.js'));

        $this->assertStringNotContainsString('createOscillator', $js,
            'El pitido tiene que venir de la confirmación del servidor, no de la lectura.');
    }
}
