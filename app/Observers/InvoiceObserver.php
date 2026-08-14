<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceObserver
{
    public function creating(Invoice $invoice): void
    {
        if (!$invoice->code) {
            // Placeholder único; created() lo reemplaza por el código legible
            // ENC-000123 una vez que ya existe el id autoincremental.
            $invoice->code = 'TMP-' . Str::uuid();
        }
    }

    public function created(Invoice $invoice): void
    {
        if (str_starts_with($invoice->code, 'TMP-')) {
            $code = 'ENC-' . str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
            DB::table('invoices')->where('id', $invoice->id)->update(['code' => $code]);
            $invoice->code = $code;
        }
    }

    /**
     * Cuando una factura pasa a "entregada" se reserva la clave de Hacienda
     * y el comprobante queda en la lista de "pendientes de envío": nunca se
     * transmite automáticamente (requisito de negocio).
     */
    public function updated(Invoice $invoice): void
    {
        if (!$invoice->wasChanged('status')) {
            return;
        }

        if ($invoice->status === Invoice::STATUS_DELIVERED) {
            app(ElectronicBillingService::class)->queueForInvoice($invoice);
        }
    }
}
