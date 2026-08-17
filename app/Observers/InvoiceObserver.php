<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\GuideCodeGenerator;
use App\Services\GuideStatusService;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvoiceObserver
{
    public function creating(Invoice $invoice): void
    {
        if (! $invoice->code) {
            // Placeholder único; created() lo reemplaza por el código guía real
            // una vez que la fila existe.
            $invoice->code = 'TMP-' . Str::uuid();
        }
    }

    public function created(Invoice $invoice): void
    {
        if (str_starts_with($invoice->code, 'TMP-')) {
            $invoice->code = $this->codigoGuia($invoice);
            DB::table('invoices')->where('id', $invoice->id)->update(['code' => $invoice->code]);
        }

        app(GuideStatusService::class)->registrarCreacion($invoice);
    }

    /**
     * Código guía con prefijo de ruta (SJ-LIM-00005).
     *
     * Si alguna sede no tiene prefijo se cae al formato viejo en vez de reventar
     * la creación: la encomienda ya se recibió físicamente y no puede perderse
     * por un dato de configuración faltante.
     */
    private function codigoGuia(Invoice $invoice): string
    {
        $invoice->loadMissing(['pickupBranch', 'deliveryBranch']);

        try {
            return app(GuideCodeGenerator::class)->generar(
                $invoice->pickupBranch,
                $invoice->deliveryBranch
            );
        } catch (\Throwable $e) {
            Log::warning('Código guía por defecto para la encomienda ' . $invoice->id . ': ' . $e->getMessage());

            return 'ENC-' . str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
        }
    }

    /**
     * Cuando una guía se entrega se reserva la clave de Hacienda y el
     * comprobante queda en "pendientes de envío": nunca se transmite solo
     * (requisito de negocio).
     */
    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status')) {
            return;
        }

        if ($invoice->status === Invoice::STATUS_DELIVERED) {
            app(ElectronicBillingService::class)->queueForInvoice($invoice);
        }
    }
}
