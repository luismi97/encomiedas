<?php

namespace App\Http\Controllers;

use App\Models\ElectronicInvoice;
use Illuminate\Support\Facades\Storage;

class ElectronicInvoiceController extends Controller
{
    public function downloadPdf(ElectronicInvoice $electronicInvoice)
    {
        abort_unless($electronicInvoice->pdf_path && Storage::disk('hacienda')->exists($electronicInvoice->pdf_path), 404);

        return Storage::disk('hacienda')->response($electronicInvoice->pdf_path, "{$electronicInvoice->clave}.pdf");
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
