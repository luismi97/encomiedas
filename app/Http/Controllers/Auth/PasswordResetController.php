<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as ReglaContrasena;

class PasswordResetController extends Controller
{
    public function solicitar()
    {
        return view('auth.forgot-password');
    }

    /**
     * Envía el enlace.
     *
     * Responde lo mismo exista o no la cuenta: decir "ese correo no está
     * registrado" le confirma a cualquiera qué correos tienen usuario.
     */
    public function enviarEnlace(Request $request)
    {
        $request->validate(
            ['email' => 'required|email'],
            [
                'email.required' => 'Escribí tu correo electrónico.',
                'email.email' => 'Ese no parece un correo válido.',
            ]
        );

        Password::sendResetLink($request->only('email'));

        return back()->with('status',
            'Si ese correo tiene una cuenta, le enviamos un enlace para restablecer la contraseña. '
            . 'Revisá también la carpeta de correo no deseado.');
    }

    public function formulario(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function actualizar(Request $request)
    {
        $request->validate(
            [
                'token' => 'required',
                'email' => 'required|email',
                'password' => ['required', 'confirmed', ReglaContrasena::min(8)],
            ],
            [
                'password.required' => 'Escribí la contraseña nueva.',
                'password.confirmed' => 'Las dos contraseñas no coinciden.',
                'password.min' => 'La contraseña necesita al menos 8 caracteres.',
            ]
        );

        $resultado = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    // Cambia el token de sesión: cierra cualquier sesión abierta
                    // con la contraseña vieja, que es el punto de restablecerla.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($resultado === Password::PASSWORD_RESET) {
            return redirect()->route('login')
                ->with('status', 'Contraseña actualizada. Ya podés iniciar sesión.');
        }

        return back()->withInput($request->only('email'))->withErrors([
            'email' => match ($resultado) {
                Password::INVALID_TOKEN => 'El enlace ya venció o se usó. Pedí uno nuevo.',
                Password::INVALID_USER  => 'No pudimos restablecer la contraseña con esos datos.',
                default                 => 'No se pudo restablecer la contraseña. Intentá de nuevo.',
            },
        ]);
    }
}
