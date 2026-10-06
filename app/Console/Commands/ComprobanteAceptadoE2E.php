<?php

namespace App\Console\Commands;

use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use App\Services\Hacienda\TiqueteElectronicoXml;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deja aceptado por Hacienda el comprobante de una guía, para las pruebas de
 * navegador.
 *
 * Lo que pasa DESPUÉS de la aceptación —ver el PDF, reenviarlo por correo, la
 * clave en el recibo— no se puede ejercitar desde el navegador en local: no
 * hay certificado ni conexión con Hacienda. Esto arma el comprobante con el
 * XML que produciría el sistema de verdad (sin firma) y lo marca aceptado.
 *
 * Igual que e2e:preparar, se niega a correr en producción: ahí inventaría un
 * comprobante que Hacienda nunca vio.
 */
class ComprobanteAceptadoE2E extends Command
{
    protected $signature = 'e2e:comprobante-aceptado {codigo : Código de la guía}';

    protected $description = 'Marca aceptado el comprobante de una guía (solo pruebas de navegador)';

    public function handle(ElectronicBillingService $servicio): int
    {
        if (app()->environment('production')) {
            $this->components->error('En producción no: inventaría un comprobante que Hacienda no aceptó.');

            return self::FAILURE;
        }

        // El mismo código puede existir en dos empresas: la guía más nueva es
        // la que acaba de crear la prueba.
        $guia = CompanyContext::sinAlcance(
            fn () => Invoice::withoutGlobalScopes()->where('code', $this->argument('codigo'))->latest('id')->first()
        );

        if (! $guia) {
            $this->components->error('No existe la guía ' . $this->argument('codigo') . '.');

            return self::FAILURE;
        }

        return CompanyContext::para($guia->company_id, function () use ($guia, $servicio) {
            $this->facturacionDePrueba();

            $comprobante = $servicio->queueForInvoice($guia->fresh());
            $xml = $comprobante->document_type === '01'
                ? (new FacturaElectronicaXml($comprobante))->build()
                : (new TiqueteElectronicoXml($comprobante))->build();

            $ruta = 'firmados/e2e/' . $comprobante->clave . '.xml';
            Storage::disk(config('hacienda.disk'))->put($ruta, $xml);

            $resumen = simplexml_load_string($xml)->ResumenFactura;

            $comprobante->forceFill([
                'status'          => ElectronicInvoice::STATUS_ACCEPTED,
                'hacienda_status' => 'aceptado',
                'accepted_at'     => now(),
                'signed_xml_path' => $ruta,
                'sub_total'       => (float) $resumen->TotalVentaNeta,
                'total_tax'       => (float) $resumen->TotalImpuesto,
                'total'           => (float) $resumen->TotalComprobante,
            ])->save();

            $this->line($comprobante->clave);

            return self::SUCCESS;
        });
    }

    /** Lo mínimo para que el sistema acepte emitir: credenciales de mentira. */
    private function facturacionDePrueba(): void
    {
        $empresa = CompanySetting::instance();

        $empresa->forceFill(array_filter([
            'enabled'          => true,
            'environment'      => 'sandbox',
            'certificate_path' => $empresa->certificate_path ?: 'certs/e2e.p12',
            'certificate_pin'  => $empresa->decryptedOrNull('certificate_pin') ? null : '1234',
            'atv_username'     => $empresa->decryptedOrNull('atv_username') ? null : 'e2e@stag.comprobanteselectronicos.go.cr',
            'atv_password'     => $empresa->decryptedOrNull('atv_password') ? null : 'e2e',
        ], fn ($valor) => $valor !== null))->save();
    }
}
