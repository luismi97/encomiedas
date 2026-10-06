<?php

namespace App\Services\Hacienda;

use App\Models\Customer;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Services\QrService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SimpleXMLElement;

/**
 * Genera el PDF del comprobante electrónico (a partir del XML ya firmado) y
 * lo guarda en el disco privado 'hacienda'.
 *
 * Es la representación gráfica que recibe el cliente por correo, así que sigue
 * el formato que los contadores ya conocen de otros emisores: emisor arriba con
 * el QR, receptor y datos del documento lado a lado, el detalle con IVA por
 * línea, el desglose del impuesto y los totales. Todo sale del XML firmado —lo
 * que Hacienda aceptó—, no de la guía, que se puede haber editado después.
 */
class PdfGenerator
{
    /** Códigos del catálogo de Hacienda, para imprimir nombres y no números. */
    private const PROVINCIAS = [
        '1' => 'San José', '2' => 'Alajuela', '3' => 'Cartago', '4' => 'Heredia',
        '5' => 'Guanacaste', '6' => 'Puntarenas', '7' => 'Limón',
    ];

    private const MEDIOS_DE_PAGO = [
        '01' => 'Efectivo', '02' => 'Tarjeta', '03' => 'Cheque', '04' => 'Transferencia',
        '05' => 'Recaudado por terceros', '06' => 'SINPE Móvil', '07' => 'Plataforma digital',
        '99' => 'Otros',
    ];

    private const UNIDADES = [
        'Sp' => 'Servicio', 'Unid' => 'Unidad', 'Os' => 'Otro servicio', 'kg' => 'Kilogramo',
    ];

    private const TIPOS_REFERENCIA = [
        '01' => 'Factura electrónica', '02' => 'Nota de débito', '03' => 'Nota de crédito',
        '04' => 'Tiquete electrónico',
    ];

    public function generate(ElectronicInvoice $electronicInvoice): string
    {
        if (!$electronicInvoice->signed_xml_path || !Storage::disk('hacienda')->exists($electronicInvoice->signed_xml_path)) {
            throw new RuntimeException("No hay XML firmado para el comprobante {$electronicInvoice->id}");
        }

        $xml = Storage::disk('hacienda')->get($electronicInvoice->signed_xml_path);

        $pdf = Pdf::loadView('pdf.comprobante-electronico', $this->datos($xml, $electronicInvoice))
            ->setPaper('letter')
            // Va adjunto en cada correo: con la fuente entera pesaba 1,2 MB.
            ->setOption('enable_font_subsetting', true);

        // El pie se pinta después de maquetar: es la única forma de saber el
        // total de páginas («Página 1 de 2») cuando el detalle es largo.
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $fuente = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $alto = $canvas->get_height();
        $canvas->page_text(36, $alto - 30, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fuente, 8, [0.3, 0.3, 0.3]);
        $canvas->page_text($canvas->get_width() - 90, $alto - 30, 'Versión ' . config('hacienda.version', '4.4'), $fuente, 8, [0.3, 0.3, 0.3]);

        $yearMonth = $electronicInvoice->issued_at->format('Y-m');
        $filename = "pdf/{$yearMonth}/{$electronicInvoice->clave}.pdf";
        Storage::disk('hacienda')->put($filename, $dompdf->output());

        $electronicInvoice->pdf_path = $filename;
        $electronicInvoice->save();

        return $filename;
    }

    /**
     * Lo que imprime la plantilla, ya leído del XML y formateado.
     *
     * @return array<string,mixed>
     */
    public function datos(string $xml, ElectronicInvoice $electronicInvoice): array
    {
        $data     = simplexml_load_string($xml);
        $emisor   = $data->Emisor;
        $receptor = $data->Receptor ?? null;
        $resumen  = $data->ResumenFactura;
        $invoice  = $electronicInvoice->invoice;

        $fecha = $electronicInvoice->issued_at
            ?? (isset($data->FechaEmision) ? Carbon::parse((string) $data->FechaEmision) : now());

        $condicion = (string) ($data->CondicionVenta ?? '');
        $plazo = (int) ($data->PlazoCredito ?? 0);

        $lineas = [];
        $desgloseIva = [];

        foreach ($data->DetalleServicio->LineaDetalle ?? [] as $linea) {
            $tarifa = (float) ($linea->Impuesto->Tarifa ?? 0);
            $impuesto = (float) ($linea->Impuesto->Monto ?? 0);
            $exonerado = (float) ($linea->Impuesto->Exoneracion->MontoExoneracion ?? 0);
            $descuento = 0.0;
            foreach ($linea->Descuento ?? [] as $d) {
                $descuento += (float) $d->MontoDescuento;
            }

            $unidad = (string) $linea->UnidadMedida;

            $lineas[] = [
                'codigo'    => (string) ($linea->CodigoCABYS ?? $linea->NumeroLinea),
                'detalle'   => (string) $linea->Detalle,
                'unidad'    => self::UNIDADES[$unidad] ?? $unidad,
                'cantidad'  => (float) $linea->Cantidad,
                'precio'    => (float) $linea->PrecioUnitario,
                'subtotal'  => (float) $linea->MontoTotal,
                'descuento' => $descuento,
                'tarifa'    => $tarifa,
                'impuesto'  => $impuesto,
                'total'     => (float) $linea->MontoTotalLinea,
            ];

            $clave = number_format($tarifa, 2, '.', '');
            $desgloseIva[$clave] ??= ['tarifa' => $tarifa, 'impuesto' => 0.0, 'exonerado' => 0.0];
            $desgloseIva[$clave]['impuesto'] += $impuesto;
            $desgloseIva[$clave]['exonerado'] += $exonerado;
        }

        $mediosDePago = [];
        foreach ($resumen->MedioPago ?? [] as $medio) {
            $codigo = (string) ($medio->TipoMedioPago ?? $medio);
            $mediosDePago[] = self::MEDIOS_DE_PAGO[$codigo] ?? $codigo;
        }

        $referencia = null;
        if (isset($data->InformacionReferencia)) {
            $ir = $data->InformacionReferencia;
            $referencia = [
                'tipo'   => self::TIPOS_REFERENCIA[(string) $ir->TipoDocIR] ?? (string) $ir->TipoDocIR,
                'numero' => (string) $ir->Numero,
                'fecha'  => isset($ir->FechaEmisionIR) ? Carbon::parse((string) $ir->FechaEmisionIR)->format('d/m/Y') : null,
                'razon'  => (string) ($ir->Razon ?? ''),
            ];
        }

        $receptorNumero = isset($receptor->Identificacion->Numero) ? (string) $receptor->Identificacion->Numero : null;

        return [
            'comprobante' => $electronicInvoice,
            'titulo'      => $electronicInvoice->typeLabel(),
            'clave'       => $electronicInvoice->clave,
            'consecutivo' => $electronicInvoice->consecutivo,
            // El QR lleva la clave: es lo que identifica el comprobante ante
            // Hacienda y lo que el contador del cliente necesita digitar.
            'qr'          => app(QrService::class)->dataUri($electronicInvoice->clave, 260),

            'emisor' => [
                'nombre'      => (string) ($emisor->Nombre ?? ''),
                'comercial'   => (string) ($emisor->NombreComercial ?? ''),
                'cedula'      => (string) ($emisor->Identificacion->Numero ?? ''),
                'telefono'    => (string) ($emisor->Telefono->NumTelefono ?? ''),
                'email'       => (string) ($emisor->CorreoElectronico ?? ''),
                'direccion'   => $this->direccion($emisor->Ubicacion ?? null),
                'actividad'   => (string) ($data->CodigoActividadEmisor ?? ''),
            ],

            'receptor' => $receptor && $receptorNumero ? $this->receptor($receptor, $receptorNumero, $invoice) : null,
            'actividadReceptor' => (string) ($data->CodigoActividadReceptor ?? ''),

            'fecha'      => $fecha,
            'condicion'  => Catalogs::SALE_CONDITIONS[$condicion] ?? ($condicion ?: '—'),
            'plazo'      => $plazo,
            'vence'      => $plazo > 0 ? $fecha->copy()->addDays($plazo) : null,
            'moneda'     => (string) ($resumen->CodigoTipoMoneda->CodigoMoneda ?? 'CRC'),
            'tipoCambio' => (float) ($resumen->CodigoTipoMoneda->TipoCambio ?? 1),
            'pago'       => implode(', ', $mediosDePago),

            'lineas'      => $lineas,
            'desgloseIva' => array_values($desgloseIva),

            'totales' => [
                'subtotal'    => (float) ($resumen->TotalVenta ?? 0),
                'descuento'   => (float) ($resumen->TotalDescuentos ?? 0),
                'impuesto'    => (float) ($resumen->TotalImpuesto ?? 0),
                'otrosCargos' => (float) ($resumen->TotalOtrosCargos ?? 0),
                'ivaDevuelto' => (float) ($resumen->TotalIVADevuelto ?? 0),
                'total'       => (float) ($resumen->TotalComprobante ?? 0),
                'exonerado'   => (float) ($resumen->TotalExonerado ?? 0),
            ],

            'referencia' => $referencia,
            'guia'       => $invoice ? $this->guia($invoice) : null,
            'leyenda'    => (string) config('hacienda.leyenda_resolucion'),
        ];
    }

    private function direccion(?SimpleXMLElement $ubicacion): string
    {
        if (! $ubicacion) {
            return '';
        }

        $provincia = self::PROVINCIAS[(string) $ubicacion->Provincia] ?? '';
        $senas = trim((string) ($ubicacion->OtrasSenas ?? ''));

        return trim($provincia . ($provincia && $senas ? '. ' : '') . $senas);
    }

    /**
     * El receptor del XML, completado con teléfono y dirección.
     *
     * El XML no los lleva (Hacienda no los exige y la guía no tiene dirección
     * fiscal), pero el formato los muestra: salen del cliente registrado con
     * esa cédula y, si no hay, de la persona de la guía a quien se facturó.
     */
    private function receptor(SimpleXMLElement $receptor, string $numero, ?Invoice $invoice): array
    {
        $cliente = Customer::where('identification', $numero)->first();

        $telefonoDeLaGuia = match ($invoice?->bill_to) {
            Invoice::BILL_TO_SENDER    => $invoice->sender_phone,
            Invoice::BILL_TO_RECIPIENT => $invoice->recipient_phone,
            default                    => null,
        };

        return [
            'nombre'    => (string) $receptor->Nombre,
            'cedula'    => $numero,
            'email'     => (string) ($receptor->CorreoElectronico ?? ''),
            'telefono'  => (string) ($cliente?->phone ?: $telefonoDeLaGuia),
            'direccion' => (string) ($cliente?->address ?? ''),
        ];
    }

    /** La encomienda a la que corresponde, en una línea como la del formato. */
    private function guia(Invoice $invoice): string
    {
        return collect([
            'Guía'     => $invoice->code,
            'Emisor'   => $invoice->sender_name,
            'Receptor' => $invoice->recipient_name,
            'Destino'  => $invoice->deliveryBranch?->name,
        ])->filter()->map(fn ($valor, $etiqueta) => "{$etiqueta}: {$valor}")->implode('  ');
    }
}
