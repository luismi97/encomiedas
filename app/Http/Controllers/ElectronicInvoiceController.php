<?php

namespace App\Http\Controllers;

use App\Models\ElectronicInvoice;
use App\Services\Hacienda\PdfGenerator;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ElectronicInvoiceController extends Controller
{
    /**
     * PDF del comprobante, regenerándolo si el archivo ya no está.
     *
     * El PDF se guardaba una sola vez, al aceptarse el comprobante, y después
     * esto devolvía 404 pelado si el archivo faltaba: pasa al mover el sitio de
     * hosting sin arrastrar storage/, o si la generación falló en su momento.
     *
     * Se puede rehacer porque sale del XML firmado, que sí está guardado. Solo
     * es irrecuperable cuando falta también ese XML.
     */
    public function downloadPdf(ElectronicInvoice $electronicInvoice, PdfGenerator $generador)
    {
        $disco = Storage::disk('hacienda');

        if (! $electronicInvoice->pdf_path || ! $disco->exists($electronicInvoice->pdf_path)) {
            abort_unless(
                $electronicInvoice->signed_xml_path && $disco->exists($electronicInvoice->signed_xml_path),
                404,
                'Este comprobante no tiene PDF ni XML firmado: todavía no se ha transmitido a Hacienda.'
            );

            try {
                $generador->generate($electronicInvoice);
            } catch (Throwable $e) {
                report($e);

                abort(500, 'No se pudo generar el PDF del comprobante: ' . $e->getMessage());
            }
        }

        return $disco->response($electronicInvoice->pdf_path, "{$electronicInvoice->clave}.pdf");
    }

    /**
     * XML de respuesta de Hacienda. Ante un rechazo es la fuente de verdad:
     * el detalle parseado es una lectura, esto es lo que Hacienda contesto.
     */
    public function downloadResponseXml(ElectronicInvoice $electronicInvoice)
    {
        abort_unless(
            $electronicInvoice->response_xml_path
                && Storage::disk('hacienda')->exists($electronicInvoice->response_xml_path),
            404
        );

        return Storage::disk('hacienda')->response(
            $electronicInvoice->response_xml_path,
            "{$electronicInvoice->clave}-respuesta.xml",
            ['Content-Type' => 'application/xml']
        );
    }
}
