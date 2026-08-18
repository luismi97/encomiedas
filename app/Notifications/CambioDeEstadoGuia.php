<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa al destinatario de los cambios que le exigen hacer algo.
 *
 * No se notifica cada paso: "listo para envío" o "en camino" no le piden nada a
 * nadie, y un correo por cada uno entrena al cliente a ignorarlos.
 */
class CambioDeEstadoGuia extends Notification implements ShouldQueue
{
    use Queueable;

    /** Solo los estados que requieren acción o cierran el ciclo. */
    public const NOTIFICABLES = [
        Invoice::STATUS_AT_DESTINATION,
        Invoice::STATUS_NEAR_DISPOSAL,
        Invoice::STATUS_DELIVERED,
    ];

    public function __construct(public Invoice $guia)
    {
    }

    public static function aplicaA(string $estado): bool
    {
        return in_array($estado, self::NOTIFICABLES, true);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mensaje = (new MailMessage())
            ->subject($this->asunto())
            ->greeting('Hola ' . ($this->guia->recipient_name ?: ''))
            ->line($this->cuerpo())
            ->line('Código de guía: ' . $this->guia->code)
            ->action('Ver el seguimiento', $this->guia->trackingUrl());

        if ($this->guia->status === Invoice::STATUS_NEAR_DISPOSAL && $this->guia->disposal_warned_at) {
            $limite = $this->guia->disposal_warned_at
                ->copy()
                ->addDays((int) config('encomiendas.disposal.dispose_after_days', 15));

            $mensaje->line('Fecha límite para retirarla: ' . $limite->format('d/m/Y') . '.');
        }

        return $mensaje->salutation('Gracias por preferirnos.');
    }

    private function asunto(): string
    {
        return match ($this->guia->status) {
            Invoice::STATUS_AT_DESTINATION => "Su encomienda {$this->guia->code} llegó y está lista para retirar",
            Invoice::STATUS_NEAR_DISPOSAL  => "Su encomienda {$this->guia->code} está por vencer",
            Invoice::STATUS_DELIVERED      => "Su encomienda {$this->guia->code} fue entregada",
            default                        => "Actualización de su encomienda {$this->guia->code}",
        };
    }

    private function cuerpo(): string
    {
        $sede = $this->guia->deliveryBranch?->name ?? 'la sucursal de destino';

        return match ($this->guia->status) {
            Invoice::STATUS_AT_DESTINATION => "Su encomienda llegó a {$sede} y está disponible para retiro.",
            Invoice::STATUS_NEAR_DISPOSAL  => "Su encomienda sigue sin retirar en {$sede}. Pasado el plazo se desechará.",
            Invoice::STATUS_DELIVERED      => 'Su encomienda fue entregada. Gracias por usar nuestro servicio.',
            default                        => 'El estado de su encomienda cambió a ' . $this->guia->statusLabel() . '.',
        };
    }
}
