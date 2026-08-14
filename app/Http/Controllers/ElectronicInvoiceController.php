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
}
