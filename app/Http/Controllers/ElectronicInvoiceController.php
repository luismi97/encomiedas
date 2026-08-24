<?php

namespace App\Http\Controllers;

use App\Models\ElectronicInvoice;
use App\Services\Hacienda\PdfGenerator;
use Illuminate\Support\Facades\Log;
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

        // Se lee y se devuelve, en vez de delegar en response(): el disco está
        // configurado con throw=false, así que un fallo de lectura —permisos,
        // típicamente— devolvía null en silencio y el navegador mostraba un
        // error sin causa. Así el motivo queda en el log y en la respuesta.
        $contenido = $disco->get($electronicInvoice->pdf_path);

        if ($contenido === null || $contenido === '') {
            $ruta = config('filesystems.disks.' . config('hacienda.disk') . '.root')
                . '/' . $electronicInvoice->pdf_path;

            Log::error('No se pudo leer el PDF del comprobante ' . $electronicInvoice->id, [
                'ruta'     => $ruta,
                'existe'   => file_exists($ruta),
                'legible'  => is_readable($ruta),
                'usuario'  => get_current_user(),
            ]);

            abort(500, 'El PDF existe pero no se puede leer. Suele ser un problema de permisos: '
                . 'revisá con «php artisan hacienda:revisar».');
        }

        return response($contenido, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $electronicInvoice->clave . '.pdf"',
        ]);
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
