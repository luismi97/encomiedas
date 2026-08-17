<?php

namespace Tests\Feature\Branches;

use App\Livewire\Branches\BranchIndex;
use App\Models\Branch;
use App\Models\ElectronicBillingSequence;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BranchIndexTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@t.test'],
            [
                'name' => 'Admin', 'username' => 'admin',
                'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
            ]
        );
    }

    private function branch(string $suc = '001', string $term = '00001', string $name = 'Central'): Branch
    {
        return Branch::create([
            'name' => $name, 'sucursal_code' => $suc, 'terminal_code' => $term, 'is_active' => true,
        ]);
    }

    private function invoiceBetween(Branch $a, Branch $b, string $status): Invoice
    {
        return Invoice::create([
            'code' => 'ENC-' . uniqid(),
            'status' => $status,
            'pickup_branch_id' => $a->id,
            'delivery_branch_id' => $b->id,
            'sender_name' => 'Remitente',
            'recipient_name' => 'Receptor',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 130, 'total' => 1130,
            'created_by' => $this->admin()->id,
        ]);
    }

    public function test_codigos_duplicados_dan_error_de_validacion_y_no_500(): void
    {
        $this->branch('001', '00001');
        $otra = $this->branch('002', '00001', 'Alajuela');

        Livewire::actingAs($this->admin())
            ->test(BranchIndex::class)
            ->call('edit', $otra->id)
            ->set('sucursal_code', '001')   // choca con la existente
            ->call('save')
            ->assertHasErrors(['sucursal_code']);

        $this->assertSame('002', $otra->fresh()->sucursal_code);
    }

    public function test_no_se_puede_eliminar_sucursal_con_encomienda_en_progreso(): void
    {
        $a = $this->branch('001', '00001');
        $b = $this->branch('002', '00001', 'Alajuela');
        $this->invoiceBetween($a, $b, Invoice::STATUS_IN_TRANSIT);

        Livewire::actingAs($this->admin())
            ->test(BranchIndex::class)
            ->call('delete', $a->id)
            ->assertSet('feedbackType', 'error')
            ->assertSee('pendiente o en camino');

        $this->assertDatabaseHas('branches', ['id' => $a->id]);
    }

    public function test_no_se_puede_eliminar_sucursal_con_historial_entregado(): void
    {
        $a = $this->branch('001', '00001');
        $b = $this->branch('002', '00001', 'Alajuela');
        $this->invoiceBetween($a, $b, Invoice::STATUS_DELIVERED);

        Livewire::actingAs($this->admin())
            ->test(BranchIndex::class)
            ->call('delete', $b->id)
            ->assertSet('feedbackType', 'error')
            ->assertSee('historial');

        $this->assertDatabaseHas('branches', ['id' => $b->id]);
    }

    public function test_no_se_puede_desactivar_sucursal_con_encomienda_en_progreso(): void
    {
        $a = $this->branch('001', '00001');
        $b = $this->branch('002', '00001', 'Alajuela');
        $this->invoiceBetween($a, $b, Invoice::STATUS_PENDING);

        Livewire::actingAs($this->admin())
            ->test(BranchIndex::class)
            ->call('toggleActive', $a->id)
            ->assertSet('feedbackType', 'error')
            ->assertSee('pendiente o en camino');

        $this->assertTrue($a->fresh()->is_active);
    }

    public function test_no_se_pueden_cambiar_codigos_con_consecutivo_emitido(): void
    {
        $a = $this->branch('001', '00001');
        ElectronicBillingSequence::create([
            'branch_id' => $a->id, 'document_type' => '01', 'last_number' => 5,
        ]);

        Livewire::actingAs($this->admin())
            ->test(BranchIndex::class)
            ->call('edit', $a->id)
            ->assertSet('codesLocked', true)
            ->set('sucursal_code', '009')
            ->call('save')
            ->assertHasErrors(['sucursal_code']);

        $this->assertSame('001', $a->fresh()->sucursal_code);
    }

    public function test_sucursal_sin_ataduras_si_se_elimina(): void
    {
        $a = $this->branch('003', '00001', 'Cartago');

        Livewire::actingAs($this->admin())
            ->test(BranchIndex::class)
            ->call('delete', $a->id);

        $this->assertDatabaseMissing('branches', ['id' => $a->id]);
    }

    public function test_se_guarda_una_sucursal_valida(): void
    {
        Livewire::actingAs($this->admin())
            ->test(BranchIndex::class)
            ->call('create')
            ->set('name', 'Heredia')
            ->set('sucursal_code', '004')
            ->set('terminal_code', '00001')
            ->set('province', '4')
            ->set('canton', '01')
            ->set('district', '01')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('branches', ['name' => 'Heredia', 'sucursal_code' => '004']);
    }
}
