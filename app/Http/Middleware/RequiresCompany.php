<?php

namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las pantallas de operación necesitan una empresa.
 *
 * El superadministrador no pertenece a ninguna (company_id en null), y sin
 * empresa en contexto el ámbito global no filtra: entrar a /invoices le
 * mostraría las guías de todos los clientes mezcladas, cada una con la
 * configuración fiscal de otro. Se corta acá, de una vez, en lugar de que cada
 * pantalla se defienda por su cuenta.
 *
 * Para entrar de verdad a una empresa está la suplantación (Empresas →
 * Entrar), que inicia sesión como su administrador y por lo tanto SÍ trae
 * company_id.
 *
 * Lo mismo del otro lado: una empresa suspendida o vencida no opera. Se le
 * cierra la sesión en vez de dejarla navegar a medias, porque «a medias» acá
 * significa emitir comprobantes fiscales.
 */
class RequiresCompany
{
    /** Rutas que existen para los dos mundos. */
    private const RUTAS_LIBRES = [
        'login',
        'logout',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'rastreo.buscar',
        'rastreo.ver',
    ];

    /** Prefijos del panel del superadministrador. */
    private const PREFIJOS_LIBRES = [
        'superadmin.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Visitante: de eso se encarga el middleware de autenticación.
        if (! $user) {
            return $next($request);
        }

        // La suspensión se revisa ANTES que nada, incluidas las peticiones de
        // Livewire: si no, a quien se le suspende la cuenta le basta con no
        // recargar la página para seguir cobrando y emitiendo desde la pantalla
        // que ya tenía abierta.
        $empresa = CompanyContext::actual();

        if ($empresa && ! $empresa->puedeOperar()) {
            return $this->cerrarSesion($request, $empresa->motivoDeBloqueo());
        }

        if ($this->esLibre($request)) {
            return $next($request);
        }

        if ($user->isSuperadmin() || $user->company_id === null) {
            return $this->alPanelDelSuperadministrador($request);
        }

        return $next($request);
    }

    private function esLibre(Request $request): bool
    {
        /*
         | Las peticiones de Livewire pasan SIEMPRE, y se reconocen por la URL y
         | no por el nombre de la ruta.
         |
         | El nombre real es «default.livewire.update», con el prefijo del perfil
         | de configuración adelante: filtrar por el prefijo «livewire.» no lo
         | agarra, y entonces cada clic del panel de superadministrador —que es
         | un componente Livewire— se respondía con una redirección al propio
         | panel. En el navegador eso se ve como un botón que recarga la página y
         | no hace nada; no hay error en el registro ni en la consola.
         |
         | Dejarlas pasar no abre nada: la pantalla que las origina ya tuvo que
         | superar este mismo control, y la empresa suspendida se corta arriba.
         */
        if ($request->is('livewire/*')) {
            return true;
        }

        $ruta = $request->route()?->getName();

        // Sin nombre de ruta no hay con qué decidir (recursos sueltos): se deja
        // pasar en vez de romper algo que hoy funciona.
        if ($ruta === null) {
            return true;
        }

        return in_array($ruta, self::RUTAS_LIBRES, true)
            || Str::startsWith($ruta, self::PREFIJOS_LIBRES);
    }

    private function alPanelDelSuperadministrador(Request $request): Response
    {
        $mensaje = 'Esa pantalla es de una empresa. Entrá a una desde el listado para verla.';

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, $mensaje);
        }

        return redirect()->route('superadmin.companies.index')->with('error', $mensaje);
    }

    private function cerrarSesion(Request $request, ?string $motivo): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', $motivo);
    }
}
