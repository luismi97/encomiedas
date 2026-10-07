<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Support\CompanyContext;
use Illuminate\Console\Command;

/**
 * Deja la facturación electrónica «lista» en una empresa de prueba, con
 * credenciales de mentira, para las pruebas de navegador.
 *
 * Alcanza para que el sistema reserve claves (el recibo sale con la clave
 * numérica) pero no para transmitir: no hay certificado de verdad. Igual que
 * e2e:preparar, se niega a correr en producción.
 */
class FacturacionDePruebaE2E extends Command
{
    protected $signature = 'e2e:facturacion-de-prueba {empresa : Identificador (slug) de la empresa}';

    protected $description = 'Activa la facturación electrónica con credenciales de prueba (solo pruebas de navegador)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->components->error('En producción no: dejaría la facturación con credenciales falsas.');

            return self::FAILURE;
        }

        $empresa = Company::where('slug', $this->argument('empresa'))->first();

        if (! $empresa) {
            $this->components->error('No existe la empresa ' . $this->argument('empresa') . '.');

            return self::FAILURE;
        }

        CompanyContext::para($empresa->id, fn () => self::activar());

        $this->components->info("Facturación de prueba activa en «{$empresa->name}».");

        return self::SUCCESS;
    }

    /** Lo mínimo para que el sistema acepte emitir: credenciales de mentira. */
    public static function activar(): void
    {
        $empresa = CompanySetting::instance();

        $empresa->forceFill(array_filter([
            'enabled'               => true,
            'environment'           => 'sandbox',
            'identification_type'   => $empresa->identification_type ?: '02',
            'identification_number' => $empresa->identification_number ?: '3101999888',
            'certificate_path'      => $empresa->certificate_path ?: 'certs/e2e.p12',
            'certificate_pin'       => $empresa->decryptedOrNull('certificate_pin') ? null : '1234',
            'atv_username'          => $empresa->decryptedOrNull('atv_username') ? null : 'e2e@stag.comprobanteselectronicos.go.cr',
            'atv_password'          => $empresa->decryptedOrNull('atv_password') ? null : 'e2e',
        ], fn ($valor) => $valor !== null))->save();
    }
}
