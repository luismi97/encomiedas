<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use App\Models\CashRegister;
use App\Models\CompanySetting;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\CajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceExportController extends Controller
{
    public function pdf(Request $request)
    {
        $query = Invoice::query()->with(['pickupBranch', 'deliveryBranch', 'assignedTo']);

        $user = $request->user();
        if ($user->isRepartidor()) {
            $query->where('assigned_to', $user->id);
        }

        // Los mismos filtros que el listado (Invoice::filtrar): el PDF tiene
        // que traer exactamente lo que se ve en pantalla.
        $query->filtrar($request->only(['from', 'to', 'status', 'branch_id', 'search', 'entrega', 'cobro', 'medio', 'creada_por']));

        $invoices = $query->latest()->get();

        $pdf = Pdf::loadView('pdf.invoices-report', [
            'invoices' => $invoices,
            'from' => $request->string('from'),
            'to' => $request->string('to'),
            'total' => $invoices->sum('total'),
            // Aparte del total facturado: lo que todavía no entró no puede
            // leerse como dinero recibido.
            'porCobrar' => round((float) $invoices->filter->tieneCobroPendiente()->sum('total'), 2),
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

        $papel = $this->papel($request, $invoice);

        // Reimpresión controlada: cada copia queda registrada y la etiqueta se
        // marca. Dos rótulos iguales sin marca es el fraude que esto evita.
        //
        // Bajo candado sobre la guía: sin él, dos clics seguidos contaban las
        // copias a la vez y salían dos «originales» sin la marca.
        $copia = DB::transaction(function () use ($invoice, $user, $papel, $request) {
            Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            return \App\Models\PrintLog::create([
                'invoice_id'  => $invoice->id,
                'user_id'     => $user->id,
                'copy_number' => $invoice->printLogs()->count() + 1,
                'paper_width' => $papel['ancho'],
                'ip'          => $request->ip(),
            ]);
        });

        return view('recibo.termico', $papel + [
            'guia'    => $invoice,
            'empresa' => CompanySetting::instance(),
            'qr'      => $qr->dataUri($invoice->trackingUrl(), 260),
            'copia'   => $copia,
        ]);
    }

    /**
     * La factura en rollo, para la misma impresora del recibo.
     *
     * La de A4 (downloadInvoice) sigue para descargar o mandar por correo,
     * pero en el mostrador se imprime en rollo, y un A4 encogido a 76 mm sale
     * con la letra a una cuarta parte de su tamaño.
     */
    public function facturaRollo(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($user->isRepartidor() && $invoice->assigned_to !== $user->id) {
            abort(403);
        }

        $invoice->load(['items', 'taxes', 'pickupBranch', 'deliveryBranch', 'electronicInvoice']);

        return view('recibo.factura', $this->papel($request, $invoice) + [
            'guia'    => $invoice,
            'empresa' => CompanySetting::instance(),
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

        $papel = $this->papel($request, $invoice);

        // Una guía sin renglones igual se despacha: en ese caso va una sola
        // etiqueta, sin detalle de bulto.
        $bultos = $invoice->items->isNotEmpty() ? $invoice->items->all() : [null];

        return view('recibo.etiqueta', $papel + [
            'guia'    => $invoice,
            'empresa' => CompanySetting::instance(),
            'bultos'  => $bultos,
            // El alto en píxeles se traduce a milímetros al imprimir; 55 da una
            // barra cómoda de escanear en rollo de 58 y de 80. La de impacto
            // la necesita más alta: el lector tiene más renglón donde enganchar
            // cuando las barras finas salen corridas.
            'barras'  => $barras->svg($invoice->code, alto: $papel['matriz'] ? 90 : 55, modulo: 2),
        ]);
    }

    /**
     * Cómo imprime el mostrador: ancho del rollo y tipo de impresora.
     *
     * Manda la caja desde la que se imprime. ?ancho= y ?impresora= lo fuerzan
     * por URL, para probar en otra impresora sin tocar la caja.
     *
     * @return array{ancho:int, matriz:bool, anchoUtil:int}
     */
    private function papel(Request $request, Invoice $invoice): array
    {
        $caja = $this->cajaQueImprime($request, $invoice);

        $ancho = $request->integer('ancho') ?: $caja?->receiptPaperWidthMm() ?? 80;
        $ancho = in_array($ancho, CashRegister::PAPER_WIDTHS, true) ? $ancho : 80;

        $matriz = match ($request->query('impresora')) {
            CashRegister::IMPRESORA_MATRIZ  => true,
            CashRegister::IMPRESORA_TERMICA => false,
            default                         => (bool) $caja?->imprimeEnMatriz(),
        };

        return [
            'ancho'     => $ancho,
            'matriz'    => $matriz,
            // La térmica imprime casi de borde a borde; la de impacto no.
            'anchoUtil' => $matriz ? CashRegister::ANCHO_IMPRIMIBLE_MATRIZ[$ancho] : $ancho,
        ];
    }

    /**
     * La caja frente a la que está quien imprime.
     *
     * Primero la del turno que tiene abierto: es donde está parado. Sin turno
     * —un administrador, o antes de abrir— se usa la sede: si todas sus cajas
     * activas imprimen igual, no hay duda; si difieren, no se adivina y queda
     * la térmica de 80, que era el comportamiento de siempre.
     */
    private function cajaQueImprime(Request $request, Invoice $invoice): ?CashRegister
    {
        $usuario = $request->user();

        if ($caja = app(CajaService::class)->sesionPropiaAbierta($usuario)?->register) {
            return $caja;
        }

        $cajas = CashRegister::where('branch_id', $usuario->branch_id ?: $invoice->pickup_branch_id)
            ->where('is_active', true)
            ->get();

        $configuraciones = $cajas
            ->map(fn (CashRegister $c) => $c->receiptPrinterType() . '|' . $c->receiptPaperWidthMm())
            ->unique();

        return $configuraciones->count() === 1 ? $cajas->first() : null;
    }

    /** Proforma en PDF, para descargar o adjuntar. */
    public function quotePdf(\App\Models\Quote $quote)
    {
        $quote->load(['items.packageType', 'originBranch', 'destinationBranch', 'creator']);

        return Pdf::loadView('pdf.quote', [
            'cotizacion' => $quote,
            'empresa'    => CompanySetting::instance(),
        ])->setPaper('letter')->stream("{$quote->code}.pdf");
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
        $dispatch->load(['lines.invoice.items', 'lines.invoice.deliveryBranch', 'originBranch', 'destinationBranch', 'driver', 'creator', 'guides.items']);

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
