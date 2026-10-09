<?php

namespace Tests\Feature\Guides;

use App\Livewire\Dispatches\DispatchIndex;
use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\Rates\RateIndex;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AbreLaCaja;
use Tests\TestCase;

/**
 * Origen y destino pueden ser la misma sede: paquetes que alguien deja y otro
 * recoge ahí mismo. Lo que sigue exigiendo sedes distintas es lo que viaja en
 * camión (cierres de envío y rutas).
 */
class SedesDistintasTest extends TestCase
{
    use RefreshDatabase;
    use AbreLaCaja;

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

        // Cobrar de contado exige caja abierta.
        $this->abrirCajaDe($this->sj, $this->admin);
    }

    private function formularioGuia()
    {
        return Livewire::actingAs($this->admin)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('sender_name', 'Marta Solano')
            ->set('recipient_name', 'José Fernández')
            ->set('items.0.package_code', 'PKG-1')
            ->set('items.0.price', 3000);
    }

    public function test_se_crea_una_guia_de_una_sede_a_si_misma(): void
    {
        $this->formularioGuia()
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->sj->id)
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::firstOrFail();
        $this->assertSame('SJ-SJ-00001', $guia->code);
        $this->assertTrue($guia->esMismaSede());
    }

    /** No viaja en ningún camión: se entrega sin pasar por un cierre. */
    public function test_una_guia_de_la_misma_sede_se_entrega_sin_despacharse(): void
    {
        $this->formularioGuia()
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->sj->id)
            ->call('save');

        $guia = Invoice::firstOrFail();
        $this->assertTrue($guia->puedePasarA(Invoice::STATUS_DELIVERED));
        $this->assertArrayNotHasKey(Invoice::STATUS_DISPATCHED, $guia->siguientesEstados($this->admin));

        $guia = app(\App\Services\GuideStatusService::class)->entregar($guia, $this->admin, 'Ana Mora');

        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->status);
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

    public function test_una_tarifa_puede_ser_de_una_sede_a_si_misma(): void
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
            ->assertHasNoErrors();

        $this->assertSame(1, Rate::count());
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
        // Esta guía sale DESDE Limón: la caja que cuenta es la de esa sede. Y
        // nadie tiene dos turnos a la vez, así que primero se cierra el de San José.
        CashSession::where('opened_by', $this->admin->id)->update(['status' => CashSession::STATUS_CLOSED, 'closed_at' => now()]);
        $this->abrirCajaDe($this->lim, $this->admin);

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
