<?php

namespace App\Notifications;

use App\Models\ElectronicInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Envía al receptor el comprobante aceptado por Hacienda, con el XML firmado y
 * el PDF adjuntos.
 *
 * No es un extra: el emisor está obligado a entregarle al receptor el
 * comprobante y el mensaje de respuesta de Hacienda. Guardarlos solo en
 * storage/ no cumple.
 */
class SendElectronicInvoice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private ElectronicInvoice $electronicInvoice)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $electronicInvoice = $this->electronicInvoice;
        $emisor = $electronicInvoice->emisor_data ?? [];

        $mail = (new MailMessage())
            ->subject($electronicInvoice->typeLabel() . ' ' . $electronicInvoice->consecutivo)
            ->markdown('emails.electronic-invoice', [
                'electronicInvoice' => $electronicInvoice,
                'emisor'            => $emisor,
            ]);

        $disk = Storage::disk(config('hacienda.disk'));

        if ($electronicInvoice->signed_xml_path && $disk->exists($electronicInvoice->signed_xml_path)) {
            $mail->attachData(
                $disk->get($electronicInvoice->signed_xml_path),
                $electronicInvoice->clave . '.xml',
                ['mime' => 'application/xml']
            );
        }

        // El mensaje de respuesta de Hacienda es parte de lo que hay que entregar.
        if ($electronicInvoice->response_xml_path && $disk->exists($electronicInvoice->response_xml_path)) {
            $mail->attachData(
                $disk->get($electronicInvoice->response_xml_path),
                $electronicInvoice->clave . '-respuesta.xml',
                ['mime' => 'application/xml']
            );
        }

        if ($electronicInvoice->pdf_path && $disk->exists($electronicInvoice->pdf_path)) {
            $mail->attachData(
                $disk->get($electronicInvoice->pdf_path),
                $electronicInvoice->clave . '.pdf',
                ['mime' => 'application/pdf']
            );
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'electronic_invoice_id' => $this->electronicInvoice->id,
            'clave' => $this->electronicInvoice->clave,
        ];
    }
}
