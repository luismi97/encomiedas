<?php

namespace Tests\Feature\Branches;

use App\Livewire\Branches\BranchIndex;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El prefijo es la parte de la sede en el código guía: SJ-LIM-00005. Va aparte
 * de sucursal_code (001), que es el de Hacienda y no se le enseña al cliente.
 */
class BranchPrefixTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@t.test'],
            ['name' => 'Admin', 'username' => 'admin', 'password' => bcrypt('x'),
             'role' => User::ROLE_ADMIN, 'is_active' => true]
        );
    }

    private function formulario()
    {
        return Livewire::actingAs($this->admin())
            ->test(BranchIndex::class)
            ->call('create')
            ->set('name', 'Limón Centro')
            ->set('sucursal_code', '006')
            ->set('terminal_code', '00001');
    }

    public function test_el_prefijo_se_guarda_en_mayusculas(): void
    {
        $this->formulario()
            ->set('prefix', 'lim')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('LIM', Branch::firstOrFail()->prefix);
    }

    public function test_el_prefijo_es_obligatorio(): void
    {
        $this->formulario()
            ->set('prefix', '')
            ->call('save')
            ->assertHasErrors('prefix')
            ->assertSee('identifica la sede en el código guía');
    }

    public function test_el_prefijo_solo_admite_de_dos_a_cuatro_letras(): void
    {
        foreach (['L', 'LIMONES', 'L1M', 'S J'] as $invalido) {
            $this->formulario()
                ->set('prefix', $invalido)
                ->call('save')
                ->assertHasErrors('prefix');
        }

        $this->assertSame(0, Branch::count());
    }

    /** Dos sedes con el mismo prefijo harían códigos guía ambiguos. */
    public function test_no_se_repite_el_prefijo_entre_sedes(): void
    {
        Branch::create([
            'name' => 'Limón', 'prefix' => 'LIM',
            'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true,
        ]);

        $this->formulario()
            ->set('prefix', 'LIM')
            ->call('save')
            ->assertHasErrors('prefix')
            ->assertSee('ya usa ese prefijo');
    }

    public function test_al_editar_una_sede_conserva_su_propio_prefijo(): void
    {
        $sede = Branch::create([
            'name' => 'San José', 'prefix' => 'SJ',
            'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true,
        ]);

        Livewire::actingAs($this->admin())
            ->test(BranchIndex::class)
            ->call('edit', $sede->id)
            ->assertSet('prefix', 'SJ')
            ->set('name', 'San José Central')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('SJ', $sede->fresh()->prefix);
    }

    public function test_el_prefijo_arma_la_ruta_de_una_tarifa(): void
    {
        $sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);

        $tarifa = \App\Models\Rate::create([
            'origin_branch_id' => $sj->id, 'destination_branch_id' => $lim->id,
            'min_weight' => 0, 'max_weight' => 5, 'price' => 3000,
        ]);

        $this->assertSame('SJ → LIM', $tarifa->rutaLabel());
    }
}
