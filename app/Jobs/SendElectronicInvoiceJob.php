<?php

namespace App\Jobs;

use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Construye, firma y transmite un comprobante a Hacienda fuera del request.
 *
 * Firmar y transmitir toma segundos por comprobante; hacerlo dentro de la
 * petición HTTP significa que un envío en bloque de 50 facturas se cae por
 * timeout a mitad de camino, dejando unos transmitidos y otros no. El job lo
 * mueve al worker (contenedor `queue`), que ya corre con --tries=3.
 */
class SendElectronicInvoiceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 120;

    /**
     * Techo del candado de unicidad. Sin esto, un worker muerto a la fuerza
     * (SIGKILL, reinicio del servidor) dejaria el comprobante bloqueado para
     * siempre y no se podria reintentar nunca.
     */
    public int $uniqueFor = 600;

    public function __construct(public int $electronicInvoiceId)
    {
    }

    /**
     * Un solo job por comprobante, aunque se despache dos veces.
     *
     * Requiere ShouldBeUnique: sin esa interfaz Laravel ni siquiera llama a
     * este metodo.
     */
    public function uniqueId(): string
    {
        return (string) $this->electronicInvoiceId;
    }

    public function handle(ElectronicBillingService $service): void
    {
        $electronicInvoice = ElectronicInvoice::find($this->electronicInvoiceId);

        if (!$electronicInvoice) {
            Log::warning("SendElectronicInvoiceJob: comprobante {$this->electronicInvoiceId} no encontrado.");
            return;
        }

        Log::info("SendElectronicInvoiceJob: transmitiendo {$electronicInvoice->clave}");

        $service->send($electronicInvoice, fromQueue: true);
    }

    /**
     * Si el job muere para siempre, el comprobante NO puede quedarse en
     * 'queued': desaparecería de la lista de pendientes sin haberse enviado.
     */
    public function failed(?Throwable $e): void
    {
        $electronicInvoice = ElectronicInvoice::find($this->electronicInvoiceId);

        if ($electronicInvoice && $electronicInvoice->status === ElectronicInvoice::STATUS_QUEUED) {
            $electronicInvoice->status = ElectronicInvoice::STATUS_ERROR;
            $electronicInvoice->error_message = 'El envío falló tras varios intentos: ' . ($e?->getMessage() ?? 'error desconocido');
            $electronicInvoice->save();
        }
    }
}
