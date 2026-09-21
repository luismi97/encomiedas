<?php

namespace Tests\Feature\Dispatches;

use App\Livewire\Dispatches\DispatchIndex;
use App\Models\Branch;
use App\Models\Dispatch;
use App\Models\GuideIncident;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El faltante que aparece, desde la pantalla.
 *
 * Es el caso que dejaba una guía varada: el cierre ya está recibido —así que no
 * admite recepciones normales— y ningún otro cierre la ofrece, porque solo se
 * ofrecen las que están en la sede de origen. Sin este botón la única salida era
 * mover el estado a mano desde la guía, si alguien se acordaba del código.
 */
class FaltanteQueApareceTest extends TestCase
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
            'delivery_branch_id'=>$this->lim->id,'sender_name'=>'Marta','recipient_name'=>'José',
            'subtotal'=>1000,'discount_amount'=>0,'tax_total'=>0,'total'=>1000,'created_by'=>$this->admin->id])->fresh();

        $this->cierre = Dispatch::create(['code'=>'CIE-000001','origin_branch_id'=>$this->sj->id,
            'destination_branch_id'=>$this->lim->id,'driver_name'=>'Chofer','created_by'=>$this->admin->id])->fresh();

        // Sale con la guía dentro y se cierra la recepción sin marcarla: faltante.
        $servicio = app(DispatchService::class);
        $servicio->agregarGuia($this->cierre, $this->guia->fresh());
        $servicio->despachar($this->cierre, $this->admin);
        $servicio->cerrarRecepcion($this->cierre->fresh(), $this->admin);
    }

    private function recepcion()
    {
        return Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('open', $this->cierre->id);
    }

    public function test_el_cierre_recibido_ofrece_marcar_que_aparecio(): void
    {
        $this->recepcion()
            ->assertSee('Faltante')
            ->assertSee('Apareció');
    }

    public function test_marcarla_la_pone_en_destino_y_cierra_el_extravio(): void
    {
        $this->recepcion()->call('recibirFaltante', $this->guia->id);

        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $this->guia->fresh()->status);
        $this->assertTrue(GuideIncident::where('invoice_id', $this->guia->id)->first()->estaResuelta());
    }

    /** Ya recuperada, la línea deja de ofrecer el botón y se lee como «Apareció». */
    public function test_despues_de_aparecer_ya_no_se_puede_volver_a_marcar(): void
    {
        $this->recepcion()->call('recibirFaltante', $this->guia->id);

        $this->recepcion()
            ->call('recibirFaltante', $this->guia->id)
            ->assertSet('feedback', 'Esta guía ya había aparecido: está en destino desde antes.');

        // Un segundo intento no duplica el paso en la bitácora.
        $this->assertSame(
            1,
            $this->guia->fresh()->statusHistories()->where('to_status', Invoice::STATUS_AT_DESTINATION)->count()
        );
    }
}
