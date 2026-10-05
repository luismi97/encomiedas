<?php

namespace App\Notifications;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Manda el reporte contable al contador con el PDF adjunto.
 *
 * Recibe el reporte ya calculado (ReporteContable::generar) y no lo vuelve a
 * consultar: en la cola no hay empresa en contexto, y así el correo dice
 * exactamente lo que se vio en pantalla al enviarlo.
 */
class EnviarReporteContable extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private array $reporte)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->reporte;

        $pdf = Pdf::loadView('pdf.reporte-contable', ['r' => $r])->setPaper('letter');
        $archivo = 'reporte-contable-' . str_replace('/', '-', $r['desde']) . '-al-' . str_replace('/', '-', $r['hasta']) . '.pdf';

        return (new MailMessage())
            ->subject("Reporte contable {$r['desde']} al {$r['hasta']} · {$r['empresa']}")
            ->greeting('Hola,')
            ->line("Le enviamos el reporte de ventas e IVA de {$r['empresa']} del {$r['desde']} al {$r['hasta']}"
                . ($r['sede'] ? " (sede {$r['sede']})." : '.'))
            ->line('Ventas netas: ₡' . number_format($r['neto']['venta'], 2))
            ->line('IVA a declarar: ₡' . number_format($r['neto']['iva'], 2))
            ->line('Solo incluye comprobantes aceptados por Hacienda; las notas de crédito restan. El detalle va en el PDF adjunto.')
            ->when($r['pendientes'] > 0, fn ($m) => $m->line(
                "Atención: {$r['pendientes']} comprobante(s) del período siguen sin respuesta de Hacienda y no están incluidos."
            ))
            ->salutation("Saludos,\n{$r['empresa']}")
            ->attachData($pdf->output(), $archivo, ['mime' => 'application/pdf']);
    }
}
