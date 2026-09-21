<?php

namespace App\Providers;

use App\Auth\UserProviderSinEmpresa;
use App\Models\Invoice;
use App\Observers\InvoiceObserver;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // En register() y no en boot(): apenas algo consulta la empresa activa
        // —y eso pasa en la primera consulta de cualquier modelo— se construye
        // el guard de autenticación, que necesita este proveedor. Registrarlo en
        // boot() llega tarde y revienta con «provider is not defined».
        $this->registrarProveedorDeUsuarios();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Invoice::observe(InvoiceObserver::class);

        $this->limpiarEmpresaEntreTrabajos();
    }

    /** Ver App\Auth\UserProviderSinEmpresa: el login no puede filtrar por empresa. */
    private function registrarProveedorDeUsuarios(): void
    {
        Auth::provider(
            'eloquent-sin-empresa',
            fn ($app, array $config) => new UserProviderSinEmpresa($app['hash'], $config['model'])
        );
    }

    /**
     * El worker de la cola no arranca de cero en cada trabajo.
     *
     * Vive horas atendiendo comprobantes de empresas distintas en el mismo
     * proceso PHP, y CompanyContext guarda la empresa en estado estático. Sin
     * borrarla entre trabajo y trabajo, el segundo comprobante heredaría la
     * empresa del primero y se firmaría con el certificado equivocado —la fuga
     * exacta que el aislamiento viene a evitar, y de las que no deja rastro
     * hasta que Hacienda rechaza—.
     */
    private function limpiarEmpresaEntreTrabajos(): void
    {
        Queue::before(fn () => CompanyContext::olvidar());
        Queue::after(fn () => CompanyContext::olvidar());
        Queue::failing(fn () => CompanyContext::olvidar());
    }
}
