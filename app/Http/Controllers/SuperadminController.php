<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Las dos acciones del superadministrador que cambian de sesión.
 *
 * Están acá y no en el componente Livewire del listado a propósito: iniciar
 * sesión como otro usuario regenera el id de sesión, y hacerlo dentro de una
 * petición de Livewire deja el componente hablando contra una sesión que ya no
 * existe. Un POST normal con su redirección es lo que el navegador entiende.
 */
class SuperadminController extends Controller
{
    /** Entra a una empresa como su administrador. */
    public function entrar(Request $request, Company $company): RedirectResponse
    {
        $admin = $company->admin();

        if (! $admin) {
            return back()->with('error', "«{$company->name}» no tiene ningún administrador al cual entrar. "
                . 'Creale uno primero.');
        }

        if (! $admin->is_active) {
            return back()->with('error', "El administrador de «{$company->name}» está desactivado.");
        }

        Impersonation::empezar($admin);

        return redirect()->route('dashboard')
            ->with('success', "Estás operando como {$admin->name}, de {$company->name}.");
    }

    /** Vuelve a ser superadministrador. */
    public function volver(Request $request): RedirectResponse
    {
        if (! Impersonation::terminar()) {
            return redirect()->route('login')->with('error', 'La sesión de superadministrador ya no está disponible.');
        }

        return redirect()->route('superadmin.companies.index')->with('success', 'Volviste al panel de empresas.');
    }

    /**
     * Cambia la contraseña del administrador de una empresa.
     *
     * Es el camino de soporte: el cliente perdió el acceso y no tiene el correo
     * de recuperación a mano. No se muestra la anterior —no se puede, está
     * cifrada— sino que se reemplaza y se le dicta la nueva.
     */
    public function contrasenaDelAdmin(Request $request, Company $company): RedirectResponse
    {
        $datos = $request->validate(
            ['password' => 'required|string|min:8'],
            [
                'password.required' => 'Escribí la contraseña nueva.',
                'password.min'      => 'La contraseña nueva necesita al menos 8 caracteres.',
            ]
        );

        $admin = $company->admin();

        if (! $admin) {
            return back()->with('error', "«{$company->name}» no tiene administrador.");
        }

        $admin->forceFill(['password' => bcrypt($datos['password'])])->save();

        return back()->with('success', "Contraseña de {$admin->email} actualizada.");
    }
}
