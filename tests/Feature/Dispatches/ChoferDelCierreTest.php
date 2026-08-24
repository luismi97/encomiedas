<?php

namespace Tests\Feature\Dispatches;

use App\Livewire\Dispatches\DispatchIndex;
use App\Models\Branch;
use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El chofer de un cierre puede venir de dos lados.
 *
 * `driver_user_id` cuando es un repartidor del sistema —y entonces ve el cierre
 * en «Mi ruta»— o `driver_name` cuando es alguien externo del que solo se anota
 * el nombre. Las pantallas mostraban únicamente el texto libre, así que elegir
 * un repartidor del desplegable se veía como «Sin chofer».
 */
class ChoferDelCierreTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;
    private User $repartidor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);

        $this->repartidor = User::create(['name'=>'Carlos Vargas','username'=>'carlos','email'=>'c@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_REPARTIDOR,'is_active'=>true]);
    }

    private function crear(array $datos)
    {
        return Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('create')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->lim->id)
            ->tap(fn ($c) => collect($datos)->each(fn ($v, $k) => $c->set($k, $v)))
            ->call('save');
    }

    // ── Lo reportado ──────────────────────────────────────────────────

    public function test_el_chofer_elegido_del_desplegable_se_guarda(): void
    {
        $this->crear(['driver_user_id' => $this->repartidor->id])->assertHasNoErrors();

        $this->assertSame($this->repartidor->id, Dispatch::firstOrFail()->driver_user_id);
    }

    /** El bug: se guardaba, pero la pantalla decía «Sin chofer». */
    public function test_el_chofer_elegido_se_ve_en_el_listado(): void
    {
        $this->crear(['driver_user_id' => $this->repartidor->id]);

        Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->assertSee('Carlos Vargas')
            ->assertDontSee('Sin chofer');
    }

    public function test_el_chofer_elegido_se_ve_al_abrir_el_cierre(): void
    {
        $this->crear(['driver_user_id' => $this->repartidor->id]);

        Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('open', Dispatch::firstOrFail()->id)
            ->assertSee('Carlos Vargas')
            ->assertDontSee('Sin chofer');
    }

    public function test_el_chofer_elegido_sale_en_el_manifiesto(): void
    {
        $this->crear(['driver_user_id' => $this->repartidor->id]);

        $html = view('pdf.dispatch', [
            'dispatch' => Dispatch::with(['lines.invoice', 'originBranch', 'destinationBranch', 'driver'])->firstOrFail(),
            'company' => \App\Models\CompanySetting::instance(),
        ])->render();

        $this->assertStringContainsString('Carlos Vargas', $html);
    }

    /** Elegir el chofer llena el nombre del manifiesto: los dos coinciden. */
    public function test_elegir_chofer_rellena_el_nombre_del_manifiesto(): void
    {
        Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('create')
            ->set('driver_user_id', $this->repartidor->id)
            ->assertSet('driver_name', 'Carlos Vargas');
    }

    // ── El otro camino sigue sirviendo ────────────────────────────────

    /** Un chofer externo no está en el sistema: solo se anota su nombre. */
    public function test_un_chofer_externo_escrito_a_mano_se_ve_igual(): void
    {
        $this->crear(['driver_name' => 'Marta Solano (transporte externo)']);

        Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->assertSee('Marta Solano')
            ->assertDontSee('Sin chofer');
    }

    /** Con los dos puestos manda el del sistema: es el que puede operar. */
    public function test_el_repartidor_del_sistema_gana_sobre_el_texto_libre(): void
    {
        $this->crear([
            'driver_user_id' => $this->repartidor->id,
            'driver_name' => 'lo que sea',
        ]);

        $this->assertSame('Carlos Vargas', Dispatch::firstOrFail()->choferLabel());
    }

    public function test_sin_chofer_lo_dice(): void
    {
        $this->crear([]);

        $this->assertNull(Dispatch::firstOrFail()->choferLabel());

        // En el listado va un guion; el texto aparece al abrir el cierre.
        Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('open', Dispatch::firstOrFail()->id)
            ->assertSee('Sin chofer');
    }
}
