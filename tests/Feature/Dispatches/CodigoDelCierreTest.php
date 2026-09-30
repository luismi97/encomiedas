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
 * El código del cierre (CIE-000001) no se repite.
 *
 * Antes el número se calculaba en una transacción y el cierre se guardaba en
 * otra: dos cierres creados en el mismo instante salían con el mismo código y
 * el segundo reventaba contra el índice único.
 */
class CodigoDelCierreTest extends TestCase
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

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);
    }

    private function crear(): Dispatch
    {
        Livewire::actingAs($this->admin)
            ->test(DispatchIndex::class)
            ->call('create')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->lim->id)
            ->call('save')
            ->assertHasNoErrors();

        return Dispatch::latest('id')->firstOrFail();
    }

    public function test_el_primer_cierre_es_el_uno_y_los_siguientes_avanzan(): void
    {
        $this->assertSame('CIE-000001', $this->crear()->code);
        $this->assertSame('CIE-000002', $this->crear()->code);
    }

    /** Un código que ya existe se salta en vez de chocar contra el único. */
    public function test_un_codigo_ya_usado_se_salta(): void
    {
        Dispatch::create([
            'code' => 'CIE-000002', 'origin_branch_id' => $this->sj->id,
            'destination_branch_id' => $this->lim->id, 'created_by' => $this->admin->id,
        ]);

        $this->assertSame('CIE-000003', $this->crear()->code);
    }

    /**
     * Lo de producción: «Duplicate entry '1-CIE-000001'». El cajero solo ve
     * los cierres de su sede, y en una sede sin cierres el número se calculaba
     * como si la empresa no tuviera ninguno.
     */
    public function test_un_cajero_de_una_sede_sin_cierres_no_repite_el_de_otra(): void
    {
        $her = Branch::create(['name'=>'Heredia','prefix'=>'HER','sucursal_code'=>'003','terminal_code'=>'00001','is_active'=>true]);

        Dispatch::create([
            'code' => 'CIE-000001', 'origin_branch_id' => $her->id,
            'destination_branch_id' => $this->lim->id, 'created_by' => $this->admin->id,
        ]);

        $cajero = User::create(['name'=>'Cajera','username'=>'cajera','email'=>'cj@t.test','branch_id'=>$this->sj->id,
            'password'=>bcrypt('x'),'role'=>User::ROLE_CAJERO,'is_active'=>true]);

        Livewire::actingAs($cajero)
            ->test(DispatchIndex::class)
            ->call('create')
            ->set('origin_branch_id', $this->sj->id)
            ->set('destination_branch_id', $this->lim->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['CIE-000001', 'CIE-000002'],
            Dispatch::withoutGlobalScopes()->orderBy('id')->pluck('code')->all()
        );
    }
}
