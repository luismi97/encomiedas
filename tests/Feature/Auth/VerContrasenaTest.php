<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * El ojito del login: muestra lo que se digitó en la contraseña. Va en JS
 * plano porque el login no carga Alpine (ver auth/login.blade.php).
 */
class VerContrasenaTest extends TestCase
{
    public function test_el_login_tiene_el_boton_para_ver_la_contrasena(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-password-toggle', false)
            ->assertSee('aria-label="Mostrar contraseña"', false)
            ->assertSee("password.type = visible ? 'text' : 'password'", false);
    }
}
