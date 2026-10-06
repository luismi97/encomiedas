<?php

namespace Tests\Feature\Roles;

use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\Users\UserIndex;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Un cajero también puede atender otras sedes además de la base, igual que el
 * dependiente: ve sus guías y abre caja en cualquiera de ellas.
 */
class CajeroVariasSedesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private Branch $pz;
    private User $admin;
    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        $this->pz  = Branch::create(['name' => 'Pérez Zeledón', 'prefix' => 'PZ', 'sucursal_code' => '003', 'terminal_code' => '00001', 'is_active' => true]);
        Tax::create(['name' => 'IVA', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);

        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'a@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->cajero = User::create(['name' => 'Carla', 'username' => 'carla', 'email' => 'c@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->sj->id]);
    }

    public function test_el_administrador_le_marca_otras_sedes(): void
    {
        Livewire::actingAs($this->admin)
            ->test(UserIndex::class)
            ->call('edit', $this->cajero->id)
            ->assertSee('Otras sedes que atiende')
            ->set('sedesExtra', [(string) $this->lim->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing([$this->sj->id, $this->lim->id], $this->cajero->fresh()->sedesIds());
    }

    public function test_ve_las_guias_y_las_cajas_de_sus_sedes(): void
    {
        $this->cajero->branches()->sync([$this->lim->id]);

        foreach ([[$this->lim, $this->pz, 'LIM-PZ-1'], [$this->pz, $this->pz, 'PZ-PZ-1']] as [$o, $d, $codigo]) {
            Invoice::create(['code' => $codigo, 'status' => Invoice::STATUS_PENDING,
                'pickup_branch_id' => $o->id, 'delivery_branch_id' => $d->id, 'sender_name' => 'M', 'recipient_name' => 'J',
                'subtotal' => 1, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1, 'created_by' => $this->admin->id]);
        }

        $this->actingAs($this->cajero);

        $this->assertSame(['LIM-PZ-1'], Invoice::pluck('code')->all());
        $this->assertEqualsCanonicalizing(
            [$this->sj->id, $this->lim->id],
            CashRegister::pluck('branch_id')->unique()->values()->all()
        );
    }

    /** Abre caja en la otra sede y cobra ahí una guía de contado. */
    public function test_cobra_en_la_caja_de_otra_sede_suya(): void
    {
        $this->cajero->branches()->sync([$this->lim->id]);
        app(CajaService::class)->abrir($this->lim->cashRegisters()->firstOrFail(), $this->cajero, 0);

        Livewire::actingAs($this->cajero)
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->lim->id)
            ->set('delivery_branch_id', $this->sj->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.price', 1000)
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::withoutGlobalScopes()->firstOrFail();
        $this->assertFalse($guia->esperandoCaja(), 'Se cobró en la caja abierta de Limón.');
    }
}
