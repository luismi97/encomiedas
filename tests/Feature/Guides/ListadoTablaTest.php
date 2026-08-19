<?php

namespace Tests\Feature\Guides;

use App\Livewire\Dispatches\DispatchIndex;
use App\Livewire\Invoices\InvoiceIndex;
use App\Livewire\Invoices\InvoiceShow;
use App\Models\Branch;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ListadoTablaTest extends TestCase
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
        return Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->sj->id,'delivery_branch_id'=>$this->lim->id,
            'sender_name'=>'Marta','recipient_name'=>'José','subtotal'=>3000,'discount_amount'=>0,'tax_total'=>0,'total'=>3000,
            'created_by'=>$this->admin->id])->fresh();
    }

    // ── 8) tabla en vez de tarjetas ───────────────────────────────────

    public function test_el_listado_es_una_tabla_con_encabezados(): void
    {
        $this->guia();

        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->assertSeeInOrder(['<thead', 'Guía', 'Ruta', 'Estado', 'Cobro', 'Total', 'Acciones'], false);
    }

    /** Cada fila con su llave: sin eso los botones se pegan a la guía equivocada. */
    public function test_cada_fila_lleva_su_propia_llave(): void
    {
        $a = $this->guia();
        $b = $this->guia();

        $html = Livewire::actingAs($this->admin)->test(InvoiceIndex::class)->html();

        $this->assertStringContainsString('wire:key="guia-' . $a->id . '"', $html);
        $this->assertStringContainsString('wire:key="guia-' . $b->id . '"', $html);
    }

    public function test_el_listado_muestra_la_ruta_con_prefijos(): void
    {
        $this->guia();

        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->assertSee('SJ')
            ->assertSee('LIM');
    }

    // ── 2) confirmación antes de cambiar estado ───────────────────────

    public function test_cambiar_estado_desde_el_listado_pide_confirmacion(): void
    {
        $guia = $this->guia();

        Livewire::actingAs($this->admin)
            ->test(InvoiceIndex::class)
            ->assertSee('wire:confirm', false)
            ->assertSee('no se deshace');
    }

    public function test_cambiar_estado_desde_el_detalle_pide_confirmacion(): void
    {
        $guia = $this->guia();

        Livewire::actingAs($this->admin)
            ->test(InvoiceShow::class, ['invoice' => $guia])
            ->assertSee('wire:confirm', false)
            ->assertSee('queda en la bitácora');
    }

    // ── 5) el cajero recibe paquetería ────────────────────────────────

    public function test_el_cajero_ve_el_boton_de_nueva_guia(): void
    {
        $cajero = User::create(['name'=>'Ana','username'=>'ana','email'=>'ana@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$this->sj->id]);

        Livewire::actingAs($cajero)
            ->test(InvoiceIndex::class)
            ->assertSee(route('invoices.create'));
    }

    public function test_el_repartidor_no_ve_el_boton_de_nueva_guia(): void
    {
        $repartidor = User::create(['name'=>'R','username'=>'r','email'=>'r@t.test','password'=>bcrypt('x'),
            'role'=>User::ROLE_REPARTIDOR,'is_active'=>true]);

        Livewire::actingAs($repartidor)
            ->test(InvoiceIndex::class)
            ->assertDontSee(route('invoices.create'));
    }

    // ── 6) el botón de despacho ───────────────────────────────────────

    /** Las dos ramas son <div> hermanos idénticos: sin llave, Livewire parcha una sobre otra. */
    public function test_las_acciones_del_cierre_llevan_llaves_distintas(): void
    {
        $guia = $this->guia();
        app(GuideStatusService::class)->cambiar($guia, Invoice::STATUS_READY, $this->admin);

        $c = Livewire::actingAs($this->admin)->test(DispatchIndex::class)
            ->call('create')->set('origin_branch_id',$this->sj->id)->set('destination_branch_id',$this->lim->id)
            ->set('driver_name','Chofer')->call('save');

        $d = Dispatch::firstOrFail();
        $c->call('open', $d->id)->call('agregar', $guia->id);

        $html = $c->html();
        $this->assertStringContainsString('wire:key="acciones-abierto-' . $d->id . '"', $html);
        $this->assertStringContainsString('Despachar cierre', $html);

        $c->call('despachar');
        $html = $c->html();

        $this->assertStringContainsString('wire:key="acciones-enruta-' . $d->id . '"', $html);
        $this->assertStringNotContainsString('wire:key="acciones-abierto-' . $d->id . '"', $html);
        $this->assertStringNotContainsString('Despachar cierre', $html);
        $this->assertStringContainsString('Cerrar recepción', $html);
    }
}
