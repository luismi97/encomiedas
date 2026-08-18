<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\RestablecerContrasena;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class RecuperarContrasenaTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        return User::create([
            'name' => 'Yolanda Campos', 'username' => 'yolanda', 'email' => 'yolanda@t.test',
            'password' => Hash::make('contrasena-vieja'), 'role' => User::ROLE_CAJERO, 'is_active' => true,
        ]);
    }

    public function test_el_login_ofrece_recuperar_la_contrasena(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('¿Olvidaste tu contraseña?')
            ->assertSee(route('password.request'));
    }

    public function test_se_envia_el_enlace_al_correo_registrado(): void
    {
        Notification::fake();
        $usuario = $this->usuario();

        $this->post(route('password.email'), ['email' => $usuario->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($usuario, RestablecerContrasena::class);
    }

    /**
     * Responder distinto según exista o no la cuenta le confirma a cualquiera
     * qué correos están registrados.
     */
    public function test_un_correo_desconocido_responde_igual(): void
    {
        Notification::fake();

        $respuesta = $this->post(route('password.email'), ['email' => 'nadie@t.test']);

        $respuesta->assertSessionHas('status');
        $respuesta->assertSessionHasNoErrors();
        Notification::assertNothingSent();
    }

    public function test_el_correo_va_en_espanol_con_el_enlace(): void
    {
        $usuario = $this->usuario();
        $mensaje = (new RestablecerContrasena('token-de-prueba'))->toMail($usuario);

        $this->assertStringContainsString('Restablecer su contraseña', $mensaje->subject);
        $this->assertSame('Restablecer contraseña', $mensaje->actionText);
        $this->assertStringContainsString('token-de-prueba', $mensaje->actionUrl);
        $this->assertStringContainsString('Hola Yolanda Campos', $mensaje->greeting);
    }

    public function test_se_restablece_la_contrasena_con_un_token_valido(): void
    {
        Event::fake();
        $usuario = $this->usuario();
        $token = Password::createToken($usuario);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'contrasena-nueva',
            'password_confirmation' => 'contrasena-nueva',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('contrasena-nueva', $usuario->fresh()->password));
        Event::assertDispatched(PasswordReset::class);
    }

    public function test_un_token_invalido_se_rechaza(): void
    {
        $usuario = $this->usuario();

        $this->post(route('password.update'), [
            'token' => 'token-inventado',
            'email' => $usuario->email,
            'password' => 'contrasena-nueva',
            'password_confirmation' => 'contrasena-nueva',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('contrasena-vieja', $usuario->fresh()->password));
    }

    public function test_las_contrasenas_deben_coincidir(): void
    {
        $usuario = $this->usuario();

        $this->post(route('password.update'), [
            'token' => Password::createToken($usuario),
            'email' => $usuario->email,
            'password' => 'contrasena-nueva',
            'password_confirmation' => 'otra-distinta',
        ])->assertSessionHasErrors('password');
    }

    public function test_una_contrasena_muy_corta_se_rechaza(): void
    {
        $usuario = $this->usuario();

        $this->post(route('password.update'), [
            'token' => Password::createToken($usuario),
            'email' => $usuario->email,
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ])->assertSessionHasErrors('password');
    }

    /** Restablecer cierra las sesiones abiertas con la contraseña vieja. */
    public function test_restablecer_invalida_el_token_de_sesion(): void
    {
        $usuario = $this->usuario();
        $usuario->forceFill(['remember_token' => 'token-viejo'])->save();

        $this->post(route('password.update'), [
            'token' => Password::createToken($usuario),
            'email' => $usuario->email,
            'password' => 'contrasena-nueva',
            'password_confirmation' => 'contrasena-nueva',
        ]);

        $this->assertNotSame('token-viejo', $usuario->fresh()->remember_token);
    }

    public function test_el_formulario_de_restablecer_precarga_el_correo(): void
    {
        $this->get(route('password.reset', ['token' => 'abc']) . '?email=yolanda@t.test')
            ->assertOk()
            ->assertSee('yolanda@t.test')
            ->assertSee('Crear una contraseña nueva');
    }

    /** Estas pantallas no cargan Alpine: el botón debe leerse sin JS. */
    public function test_las_pantallas_no_dependen_de_js_para_su_estado(): void
    {
        foreach ([route('password.request'), route('password.reset', ['token' => 'abc'])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $form = substr($html, strpos($html, '<form'), strpos($html, '</form>') - strpos($html, '<form'));

            foreach (['x-data', 'x-show', 'x-text'] as $directiva) {
                $this->assertStringNotContainsString($directiva, $form);
            }

            $this->assertStringContainsString('data-auth-spinner hidden', $form);
        }
    }
}
