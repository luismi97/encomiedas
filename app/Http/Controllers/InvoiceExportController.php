<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class InvoiceExportController extends Controller
{
    public function pdf(Request $request)
    {
        $query = Invoice::query()->with(['pickupBranch', 'deliveryBranch', 'assignedTo']);

        $user = $request->user();
        if ($user->isRepartidor()) {
            $query->where('assigned_to', $user->id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->string('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->string('to'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('branch_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('pickup_branch_id', $request->integer('branch_id'))
                    ->orWhere('delivery_branch_id', $request->integer('branch_id'));
            });
        }
        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', "%{$term}%")
                    ->orWhere('recipient_name', 'like', "%{$term}%")
                    ->orWhere('sender_name', 'like', "%{$term}%");
            });
        }

        $invoices = $query->latest()->get();

        $pdf = Pdf::loadView('pdf.invoices-report', [
            'invoices' => $invoices,
            'from' => $request->string('from'),
            'to' => $request->string('to'),
            'total' => $invoices->sum('total'),
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('reporte-encomiendas.pdf');
    }

    /** Descarga la factura de la encomienda con toda la información requerida. */
    public function downloadInvoice(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        if ($user->isRepartidor() && $invoice->assigned_to !== $user->id) {
            abort(403);
        }

        $invoice->load(['items', 'taxes', 'pickupBranch', 'deliveryBranch', 'creator', 'assignedTo', 'electronicInvoice']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => CompanySetting::instance(),
        ])->setPaper('a4');

        return $pdf->stream("{$invoice->code}.pdf");
    }
}
