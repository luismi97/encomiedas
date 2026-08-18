<?php

namespace Tests\Feature\Guides;

use App\Livewire\Dispatches\DispatchIndex;
use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\Rates\RateIndex;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Una encomienda es un traslado ENTRE sedes.
 *
 * Origen y destino iguales no es un envío, y además rompe el código guía, que
 * se arma con los dos prefijos: SJ-SJ-00001 no significa nada.
 */
class SedesDistintasTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);

        Tax::create(['name' => 'IVA general', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function formularioGuia()
    {
        return Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('sender_name', 'Marta Solano')
            ->set('recipient_name', 'José Fernández')
            ->set('items.0.package_code', 'PKG-1')
            ->set('items.0.price', 3000);
    }

    public function test_no_se_crea_una_guia_de_una_sede_a_si_misma(): void
    {
        $this->formularioGuia()
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->sj->id)
            ->call('save')
            ->assertHasErrors('delivery_branch_id')
            ->assertSee('tiene que ser distinta de la de origen');

        $this->assertSame(0, Invoice::count());
    }

    public function test_una_guia_entre_sedes_distintas_si_se_crea(): void
    {
        $this->formularioGuia()
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('SJ-LIM-00001', Invoice::firstOrFail()->code);
    }

    public function test_una_tarifa_no_puede_ser_de_una_sede_a_si_misma(): void
    {
        Livewire::actingAs($this->admin)
            ->test(RateIndex::class)
            ->call('create')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->sj->id)
            ->set('min_weight', 0)
            ->set('max_weight', 5)
            ->set('price', 3000)
            ->call('save')
            ->assertHasErrors('destination_branch_id')
            ->assertSee('no existen envíos de una sede a sí misma');

        $this->assertSame(0, Rate::count());
    }

    /** Una tarifa base sin sedes declaradas sigue siendo válida. */
    public function test_una_tarifa_sin_sedes_sigue_siendo_valida(): void
    {
        Livewire::actingAs($this->admin)
            ->test(RateIndex::class)
            ->call('create')
            ->set('min_weight', 0)
            ->set('max_weight', 5)
            ->set('price', 3000)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Rate::count());
    }

    public function test_un_cierre_de_envio_tampoco_puede_ser_a_la_misma_sede(): void
    {
        Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('create')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->sj->id)
            ->call('save')
            ->assertHasErrors('destination_branch_id');
    }

    /** El código guía se arma con los dos prefijos: iguales no distingue nada. */
    public function test_el_codigo_guia_siempre_lleva_dos_prefijos_distintos(): void
    {
        $this->formularioGuia()
            ->set('pickup_branch_id', $this->lim->id)
            ->set('delivery_branch_id', $this->sj->id)
            ->call('save')
            ->assertHasNoErrors();

        $codigo = Invoice::firstOrFail()->code;
        [$origen, $destino] = explode('-', $codigo);

        $this->assertNotSame($origen, $destino);
    }
}
