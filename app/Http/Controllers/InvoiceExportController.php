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
    /**
     * Etiqueta térmica de la guía, con su QR.
     *
     * Se imprime desde el navegador contra el driver del sistema: no hace falta
     * WebUSB ni un puente local, y funciona igual en Windows, Mac o una tablet.
     */
    public function reciboTermico(Request $request, Invoice $invoice, \App\Services\QrService $qr)
    {
        $user = $request->user();

        if ($user->isRepartidor() && $invoice->assigned_to !== $user->id) {
            abort(403);
        }

        $invoice->load(['items', 'pickupBranch', 'deliveryBranch']);

        // El ancho manda el de la sede de origen, que es donde se imprime.
        $ancho = $request->integer('ancho')
            ?: $invoice->pickupBranch?->receiptPaperWidthMm()
            ?? 80;

        $ancho = in_array($ancho, \App\Models\Branch::PAPER_WIDTHS, true) ? $ancho : 80;

        // Reimpresión controlada: cada copia queda registrada y la etiqueta se
        // marca. Dos rótulos iguales sin marca es el fraude que esto evita.
        $copia = \App\Models\PrintLog::create([
            'invoice_id'  => $invoice->id,
            'user_id'     => $user->id,
            'copy_number' => $invoice->printLogs()->count() + 1,
            'paper_width' => $ancho,
            'ip'          => $request->ip(),
        ]);

        return view('recibo.termico', [
            'guia'    => $invoice,
            'empresa' => CompanySetting::instance(),
            'ancho'   => $ancho,
            'qr'      => $qr->dataUri($invoice->trackingUrl(), 260),
            'copia'   => $copia,
        ]);
    }

    /**
     * Etiqueta que se pega al paquete, con código de barras escaneable.
     *
     * Separada del recibo del cliente a propósito: son dos documentos con dos
     * destinos distintos. El recibo lleva montos y se lo lleva quien despacha;
     * la etiqueta queda a la vista de cualquiera que manipule el bulto, así
     * que no lleva plata, y en cambio lleva el código de barras y la ruta en
     * grande.
     *
     * Sale una etiqueta por bulto: si la guía trae tres paquetes, los tres
     * necesitan la suya o se pierden al separarse en la bodega.
     */
    public function etiquetaPaquete(Request $request, Invoice $invoice, \App\Services\BarcodeService $barras)
    {
        $user = $request->user();

        if ($user->isRepartidor() && $invoice->assigned_to !== $user->id) {
            abort(403);
        }

        $invoice->load(['items', 'pickupBranch', 'deliveryBranch']);

        $ancho = $request->integer('ancho')
            ?: $invoice->pickupBranch?->receiptPaperWidthMm()
            ?? 80;

        $ancho = in_array($ancho, \App\Models\Branch::PAPER_WIDTHS, true) ? $ancho : 80;

        // Una guía sin renglones igual se despacha: en ese caso va una sola
        // etiqueta, sin detalle de bulto.
        $bultos = $invoice->items->isNotEmpty() ? $invoice->items->all() : [null];

        return view('recibo.etiqueta', [
            'guia'    => $invoice,
            'empresa' => CompanySetting::instance(),
            'ancho'   => $ancho,
            'bultos'  => $bultos,
            // El alto en píxeles se traduce a milímetros al imprimir; 55 da una
            // barra cómoda de escanear en rollo de 58 y de 80.
            'barras'  => $barras->svg($invoice->code, alto: 55, modulo: 2),
        ]);
    }

    /** Estado de cuenta consolidado de un período de crédito. */
    public function creditStatementPdf(\App\Models\CreditStatement $statement)
    {
        $statement->load(['customer', 'issuer', 'payments', 'guides.pickupBranch', 'guides.deliveryBranch']);

        return Pdf::loadView('pdf.credit-statement', [
            'estado'  => $statement,
            'company' => CompanySetting::instance(),
        ])->setPaper('letter')->stream("{$statement->code}.pdf");
    }

    /** Reporte de cierre de caja, con el arqueo y espacio para firmas. */
    public function cashSessionPdf(\App\Models\CashSession $session, \App\Services\CajaService $caja)
    {
        $session->load(['movements.invoice', 'movements.creator', 'counts.denomination', 'opener', 'closer', 'register.branch', 'branch']);

        return Pdf::loadView('pdf.cash-session', [
            'sesion'   => $session,
            'porMedio' => $caja->totalesPorMedio($session),
            'company'  => CompanySetting::instance(),
        ])->setPaper('letter')->stream("cierre-caja-{$session->id}.pdf");
    }

    /** Manifiesto imprimible del cierre de envío, con espacio para firmas. */
    public function dispatchPdf(\App\Models\Dispatch $dispatch)
    {
        $dispatch->load(['lines.invoice.items', 'lines.invoice.deliveryBranch', 'originBranch', 'destinationBranch', 'creator', 'guides.items']);

        return Pdf::loadView('pdf.dispatch', [
            'dispatch' => $dispatch,
            'company'  => CompanySetting::instance(),
        ])->setPaper('letter')->stream("{$dispatch->code}.pdf");
    }

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
            // Data URI: DomPDF no sale a la red a buscar una imagen.
            'qr'      => app(\App\Services\QrService::class)->dataUri($invoice->trackingUrl(), 220),
        ])->setPaper('a4');

        return $pdf->stream("{$invoice->code}.pdf");
    }
}
