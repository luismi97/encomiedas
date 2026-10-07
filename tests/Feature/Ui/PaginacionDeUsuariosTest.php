<?php

namespace Tests\Feature\Ui;

use App\Livewire\Users\UserIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Los listados no paginan: crecen hacia abajo con scroll infinito, igual que
 * guías y clientes. Ver App\Livewire\Concerns\ScrollInfinito.
 */
class PaginacionDeUsuariosTest extends TestCase
{
    use RefreshDatabase;

    public function test_los_usuarios_cargan_por_tandas_sin_paginas(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);

        foreach (range(1, 55) as $n) {
            User::create(['name' => sprintf('Usuario %02d', $n), 'email' => "u{$n}@t.test",
                'password' => bcrypt('x'), 'role' => User::ROLE_REPARTIDOR, 'is_active' => true]);
        }

        // Orden por nombre: «Admin» y luego Usuario 01..49 llenan la primera tanda de 50.
        Livewire::actingAs($admin)
            ->test(UserIndex::class)
            ->assertSee('Usuario 49')
            ->assertDontSee('Usuario 50')
            ->assertSee('Cargar más')
            ->assertDontSee('?page=', false)
            ->call('cargarMas')
            ->assertSee('Usuario 55')
            ->assertSee('No hay más usuarios');
    }
}
