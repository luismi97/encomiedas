<?php

namespace App\Http\Controllers;

use App\Services\ImportadorDeClientes;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerImportController extends Controller
{
    /**
     * La plantilla de clientes, para llenarla en Excel.
     *
     * Va por una descarga y no por un enlace a un archivo estático porque los
     * encabezados los define el importador: si mañana se agrega una columna, la
     * plantilla la trae sola y no hay dos verdades sobre el formato.
     */
    public function plantilla(ImportadorDeClientes $importador): StreamedResponse
    {
        $contenido = $importador->plantilla();

        return response()->streamDownload(
            fn () => print($contenido),
            'plantilla-clientes.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }
}
