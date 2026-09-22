<?php

namespace Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('identity.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)
            ->subject('Reset your password')
            ->line('You requested a password reset for your account.')
            ->action('Reset password', $url)
            ->line('If you did not request this, no action is required.');
    }
}
