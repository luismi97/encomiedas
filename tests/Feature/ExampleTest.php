<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** La raíz manda al dashboard, y a un invitado el dashboard lo manda al login. */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));

        $this->get('/dashboard')->assertRedirect(route('login'));

        $this->get('/login')->assertOk();
    }
}
