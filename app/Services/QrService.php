<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Códigos QR de las guías.
 *
 * Se generan al vuelo y no se guardan en disco: son deterministas a partir del
 * código de guía, así que un archivo por guía sería un caché que hay que
 * invalidar sin ganar nada.
 */
class QrService
{
    /**
     * PNG en data URI, listo para incrustar en HTML o en un PDF de DomPDF, que
     * no puede salir a buscar una imagen por red.
     */
    public function dataUri(string $contenido, int $tamano = 300): string
    {
        $resultado = Builder::create()
            ->writer(new PngWriter())
            ->data($contenido)
            ->encoding(new Encoding('UTF-8'))
            // Alta corrección de errores: la etiqueta va pegada a un paquete y
            // termina rayada, mojada o doblada.
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size($tamano)
            ->margin(8)
            ->build();

        return $resultado->getDataUri();
    }

    /** SVG para pantalla: escala sin pixelarse y pesa menos. */
    public function svg(string $contenido, int $tamano = 200): string
    {
        return Builder::create()
            ->writer(new SvgWriter())
            ->data($contenido)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size($tamano)
            ->margin(4)
            ->build()
            ->getString();
    }
}
