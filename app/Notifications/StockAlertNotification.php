<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StockAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $businessId,
        private readonly string $type,
        private readonly string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Alerta de inventario - ' . $this->businessId)
            ->line($this->message)
            ->line('Tipo de alerta: ' . $this->type)
            ->line('Negocio: ' . $this->businessId);
    }
}
