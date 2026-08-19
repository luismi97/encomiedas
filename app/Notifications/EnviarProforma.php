<?php

namespace App\Notifications;

use App\Models\CompanySetting;
use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Manda la proforma al cliente con el PDF adjunto.
 *
 * El PDF se genera en el momento y no se guarda: una cotización se puede editar
 * hasta que la aceptan, y un archivo en disco quedaría desactualizado sin que
 * nadie lo note.
 */
class EnviarProforma extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Quote $cotizacion)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $empresa = CompanySetting::instance();
        $nombre = $empresa->commercial_name ?: $empresa->name;

        $pdf = Pdf::loadView('pdf.quote', [
            'cotizacion' => $this->cotizacion->loadMissing(['items.packageType', 'originBranch', 'destinationBranch']),
            'empresa' => $empresa,
        ])->setPaper('letter');

        return (new MailMessage())
            ->subject("Cotización {$this->cotizacion->code} · {$nombre}")
            ->greeting("Hola {$this->cotizacion->customer_name},")
            ->line("Le enviamos la cotización de su envío {$this->cotizacion->rutaLabel()}.")
            ->line('Total: ₡' . number_format((float) $this->cotizacion->total, 2))
            ->when($this->cotizacion->valid_until, fn ($m) => $m->line(
                'Precio válido hasta el ' . $this->cotizacion->valid_until->format('d/m/Y') . '.'
            ))
            ->line('Adjuntamos el detalle en PDF.')
            ->salutation("Saludos,\n{$nombre}")
            ->attachData($pdf->output(), "{$this->cotizacion->code}.pdf", ['mime' => 'application/pdf']);
    }
}
