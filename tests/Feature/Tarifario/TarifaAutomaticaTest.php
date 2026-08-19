<?php

namespace Tests\Feature\Tarifario;

use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\Rate;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El tarifario encontraba la tarifa, pero solo al presionar «Calcular con el
 * tarifario»: quien creaba una tarifa y se iba a facturar veía el precio en
 * blanco y concluía que el tarifario no servía.
 */
class TarifaAutomaticaTest extends TestCase
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
        Tax::create(['name'=>'IVA','percent'=>13,'hacienda_code'=>'08','is_default'=>true,'is_active'=>true]);
        $this->admin = User::create(['name'=>'A','username'=>'a','email'=>'a@t.test','password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);

        Rate::create(['name'=>'Metro liviana','origin_branch_id'=>$this->sj->id,'destination_branch_id'=>$this->lim->id,
            'min_weight'=>0,'max_weight'=>5,'price'=>3500,'price_per_extra_kg'=>0,'is_active'=>true]);
        Rate::create(['name'=>'Metro pesada','origin_branch_id'=>$this->sj->id,'destination_branch_id'=>$this->lim->id,
            'min_weight'=>5,'max_weight'=>20,'price'=>8000,'price_per_extra_kg'=>0,'is_active'=>true]);
    }

    private function form()
    {
        return Livewire::actingAs($this->admin)->test(InvoiceForm::class);
    }

    /** El bug: el precio salía en blanco sin tocar ningún botón. */
    public function test_elegir_la_ruta_y_el_peso_trae_el_precio_solo(): void
    {
        $this->form()
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('items.0.weight', '2')
            ->assertSet('items.0.price', 3500.0);
    }

    public function test_cambiar_el_peso_recotiza(): void
    {
        $this->form()
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('items.0.weight', '2')
            ->assertSet('items.0.price', 3500.0)
            ->set('items.0.weight', '9')
            ->assertSet('items.0.price', 8000.0);
    }

    /** Las dimensiones también: un bulto liviano y enorme cambia de tramo. */
    public function test_las_dimensiones_recotizan_por_peso_volumetrico(): void
    {
        $this->form()
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('items.0.weight', '1')
            ->assertSet('items.0.price', 3500.0)
            // 60×50×40 / 5000 = 24 kg volumétricos: se sale de los dos tramos.
            ->set('items.0.length_cm', '60')
            ->set('items.0.width_cm', '50')
            ->set('items.0.height_cm', '40')
            ->assertSet('quote.sin_tarifa', true);
    }

    /** Un acuerdo puntual no se puede perder porque alguien corrija el peso. */
    public function test_un_precio_digitado_a_mano_no_se_pisa(): void
    {
        $this->form()
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('items.0.weight', '2')
            ->assertSet('items.0.price', 3500.0)
            ->set('items.0.price', 2000)      // el cajero lo pisa
            ->set('items.0.weight', '3')      // y después corrige el peso
            ->assertSet('items.0.price', 2000);
    }

    public function test_sin_tarifa_avisa_en_vez_de_dejar_cero(): void
    {
        Rate::query()->update(['is_active' => false]);

        $this->form()
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('items.0.weight', '2')
            ->assertSet('quote.sin_tarifa', true)
            ->assertSet('items.0.price', '');
    }

    /** El botón manual sigue existiendo para recalcular a propósito. */
    public function test_el_boton_de_cotizar_sigue_funcionando(): void
    {
        $this->form()
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('items.0.weight', '2')
            ->set('items.0.price', 999)
            ->call('cotizar')
            ->assertSet('quote.precio_total', 3500.0);
    }
}
