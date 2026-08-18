<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Enlace para restablecer la contraseña.
 *
 * Propia y no la de Laravel porque esa viene en inglés y el sistema es de cara
 * a cajeros y choferes en Costa Rica.
 */
class RestablecerContrasena extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = Config::get('auth.passwords.users.expire', 60);

        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage())
            ->subject('Restablecer su contraseña — ' . config('app.name'))
            ->greeting('Hola ' . $notifiable->name)
            ->line('Recibimos una solicitud para restablecer la contraseña de su cuenta.')
            ->action('Restablecer contraseña', $url)
            ->line("El enlace vence en {$minutos} minutos.")
            ->line('Si no fue usted quien lo solicitó, ignore este mensaje: su contraseña no cambia.')
            ->salutation('— ' . config('app.name'));
    }
}
