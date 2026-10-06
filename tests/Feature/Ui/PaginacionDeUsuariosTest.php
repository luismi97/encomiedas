<?php

namespace Tests\Feature\Ui;

use App\Livewire\Users\UserIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Paginar usuarios daba 405: sin WithPagination, los enlaces de página que
 * se pintaban tras una acción de Livewire apuntaban a /livewire/update.
 */
class PaginacionDeUsuariosTest extends TestCase
{
    use RefreshDatabase;

    public function test_cambiar_de_pagina_funciona_y_no_apunta_a_livewire_update(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);

        foreach (range(1, 14) as $n) {
            User::create(['name' => sprintf('Usuario %02d', $n), 'email' => "u{$n}@t.test",
                'password' => bcrypt('x'), 'role' => User::ROLE_REPARTIDOR, 'is_active' => true]);
        }

        Livewire::actingAs($admin)
            ->test(UserIndex::class)
            ->call('create') // cualquier acción: es después de una que se rompía
            ->assertDontSee('livewire/update?page', false)
            ->call('gotoPage', 2)
            ->assertSee('Usuario 14')
            ->assertDontSee('Usuario 01');
    }
}
