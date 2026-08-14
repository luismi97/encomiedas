<?php

namespace App\Services\Hacienda;

use App\Models\ElectronicInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Genera el PDF del comprobante electrónico (a partir del XML ya firmado) y
 * lo guarda en el disco privado 'hacienda'.
 */
class PdfGenerator
{
    public function generate(ElectronicInvoice $electronicInvoice): string
    {
        if (!$electronicInvoice->signed_xml_path || !Storage::disk('hacienda')->exists($electronicInvoice->signed_xml_path)) {
            throw new RuntimeException("No hay XML firmado para el comprobante {$electronicInvoice->id}");
        }

        $xml = Storage::disk('hacienda')->get($electronicInvoice->signed_xml_path);
        $html = $this->xmlToHtml($xml, $electronicInvoice);

        $pdf = Pdf::loadHTML($html)->setPaper('a4')
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 10)
            ->setOption('margin-right', 10);

        $yearMonth = $electronicInvoice->issued_at->format('Y-m');
        $filename = "pdf/{$yearMonth}/{$electronicInvoice->clave}.pdf";
        Storage::disk('hacienda')->put($filename, $pdf->output());

        $electronicInvoice->pdf_path = $filename;
        $electronicInvoice->save();

        return $filename;
    }

    private function xmlToHtml(string $xml, ElectronicInvoice $electronicInvoice): string
    {
        $data     = simplexml_load_string($xml);
        $emisor   = $data->Emisor;
        $receptor = $data->Receptor ?? null;
        $detalles = $data->DetalleServicio->LineaDetalle ?? [];
        $resumen  = $data->ResumenFactura;

        $invoice = $electronicInvoice->invoice;

        $emisorNom   = (string) ($emisor->Nombre ?? 'N/A');
        $emisorCed   = (string) ($emisor->Identificacion->Numero ?? '—');
        $emisorTel   = (string) ($emisor->Telefono->NumTelefono ?? '—');
        $emisorEmail = (string) ($emisor->CorreoElectronico ?? '—');

        $receptorNom = optional($receptor)->Nombre ?? 'Consumidor Final';
        $receptorCed = isset($receptor->Identificacion->Numero) ? (string) $receptor->Identificacion->Numero : '—';

        $fecha = $electronicInvoice->issued_at->format('d/m/Y');
        $hora  = $electronicInvoice->issued_at->format('h:i A');

        $subtotal = number_format((float) ($resumen->TotalVentaNeta ?? 0), 2, '.', ',');
        $impuesto = number_format((float) ($resumen->TotalImpuesto ?? 0), 2, '.', ',');
        $total    = number_format((float) ($resumen->TotalComprobante ?? 0), 2, '.', ',');

        $rows = '';
        foreach ($detalles as $linea) {
            $desc       = htmlspecialchars((string) $linea->Detalle);
            $precio     = number_format((float) $linea->PrecioUnitario, 2, '.', ',');
            $impLinea   = number_format((float) ($linea->Impuesto->Monto ?? 0), 2, '.', ',');
            $totalLinea = number_format((float) $linea->MontoTotalLinea, 2, '.', ',');

            $rows .= <<<HTML
            <tr>
              <td class="td-desc">$desc</td>
              <td class="td-price">₡ $precio</td>
              <td class="td-imp">₡ $impLinea</td>
              <td class="td-total">₡ $totalLinea</td>
            </tr>
            HTML;
        }

        $pickup   = htmlspecialchars($invoice?->pickupBranch?->name ?? '—');
        $delivery = htmlspecialchars($invoice?->deliveryBranch?->name ?? '—');
        $code     = htmlspecialchars($invoice?->code ?? '');

        return <<<HTML
        <html>
        <head>
        <meta charset="utf-8">
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
            .header { background: linear-gradient(135deg, #2563eb 0%, #1e3a8a 100%); color: #fff; padding: 16px; border-radius: 8px; }
            .header h1 { margin: 0; font-size: 18px; }
            .muted { color: #6b7280; }
            table { width: 100%; border-collapse: collapse; margin-top: 12px; }
            th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
            th { background: #f3f4f6; }
            .td-price, .td-imp, .td-total { text-align: right; }
            .totals { margin-top: 10px; width: 260px; margin-left: auto; }
            .totals div { display: flex; justify-content: space-between; padding: 3px 0; }
            .grid { display: flex; gap: 24px; margin-top: 14px; }
            .box { flex: 1; }
            .box h3 { margin: 0 0 4px 0; font-size: 13px; }
            .clave { font-size: 9px; word-break: break-all; color: #6b7280; }
        </style>
        </head>
        <body>
            <div class="header">
                <h1>Comprobante de Encomienda — {$code}</h1>
                <div>{$electronicInvoice->typeLabel()} · {$fecha} {$hora}</div>
            </div>

            <div class="grid">
                <div class="box">
                    <h3>Emisor</h3>
                    {$emisorNom}<br>
                    Cédula: {$emisorCed}<br>
                    Tel: {$emisorTel} · {$emisorEmail}
                </div>
                <div class="box">
                    <h3>Receptor</h3>
                    {$receptorNom}<br>
                    Identificación: {$receptorCed}
                </div>
                <div class="box">
                    <h3>Ruta</h3>
                    Recogida: {$pickup}<br>
                    Entrega: {$delivery}
                </div>
            </div>

            <table>
                <thead>
                    <tr><th>Detalle</th><th>Precio</th><th>Impuesto</th><th>Total</th></tr>
                </thead>
                <tbody>
                    {$rows}
                </tbody>
            </table>

            <div class="totals">
                <div><span>Subtotal</span><span>₡ {$subtotal}</span></div>
                <div><span>Impuestos</span><span>₡ {$impuesto}</span></div>
                <div><strong>Total</strong><strong>₡ {$total}</strong></div>
            </div>

            <p class="clave">Clave: {$electronicInvoice->clave}</p>
        </body>
        </html>
        HTML;
    }
}
