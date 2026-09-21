<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerificationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu codigo de verificacion de Spikia')
            ->markdown('mail.verification-code', [
                'name' => $notifiable->name ?? 'usuario',
                'code' => $this->code,
                'expiresInMinutes' => 15,
            ]);
    }
}