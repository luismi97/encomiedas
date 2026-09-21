<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required'],
        ]);

        $field = str_contains($request->input('login'), '@') ? 'email' : 'username';

        // Sin ámbito global: entrar es, por definición, una operación de antes
        // de tener empresa. Si algo dejó una fijada —una sesión a medio cerrar,
        // una suplantación— el usuario de cualquier otra empresa simplemente no
        // aparecería, y el sistema diría que la contraseña está mal.
        $user = User::withoutGlobalScopes()->where($field, $request->input('login'))->first();

        if (! $user || ! Auth::attempt(['email' => $user->email, 'password' => $request->input('password')], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'login' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        if (! $user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'login' => 'Esta cuenta está desactivada. Contacte a un administrador.',
            ]);
        }

        // La empresa suspendida o vencida se rechaza acá y no en el middleware,
        // para que el mensaje diga POR QUÉ. Dejarla entrar y sacarla después
        // deja al usuario mirando un login sin explicación.
        if ($motivo = $user->company?->motivoDeBloqueo()) {
            Auth::logout();
            throw ValidationException::withMessages(['login' => $motivo]);
        }

        $request->session()->regenerate();

        // El contexto se resolvió antes de que hubiera usuario autenticado: si
        // no se olvida, la primera consulta de la sesión saldría sin empresa.
        CompanyContext::olvidar();

        return redirect()->intended($this->destino($user));
    }

    /**
     * A dónde va cada quien después de entrar.
     *
     * El superadministrador no tiene tablero de operación —no opera ninguna
     * empresa—, así que su casa es el listado de empresas.
     */
    private function destino(User $user): string
    {
        return $user->isSuperadmin()
            ? route('superadmin.companies.index')
            : route('dashboard');
    }

    public function logout(Request $request)
    {
        CompanyContext::olvidar();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
