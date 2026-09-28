<?php

namespace Modules\Notifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Notifications\Models\NotificationPreference;
use Modules\Notifications\NotificationRegistry;

class CoreNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $category,
        public string $titleKey,
        public string $messageKey,
        public array $parameters = [],
        public ?array $action = null,
        public array $context = [],
        public string $priority = 'default',
    ) {
        $this->onQueue($priority);
    }

    public function via(object $notifiable): array
    {
        $definition = NotificationRegistry::CATEGORIES[$this->category] ?? throw new \InvalidArgumentException('Unknown notification category.');
        $preference = NotificationPreference::query()->whereBelongsTo($notifiable, 'user')->where('category', $this->category)->first();
        $channels = [];

        if ($preference?->database_enabled ?? $definition['database']) {
            $channels[] = 'database';
        }
        if ($preference?->mail_enabled ?? $definition['mail']) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return ['schema_version' => 1, 'category' => $this->category, 'title_key' => $this->titleKey, 'message_key' => $this->messageKey, 'parameters' => $this->parameters, 'action' => $this->action, 'context' => $this->context];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__($this->titleKey, $this->parameters))->line(__($this->messageKey, $this->parameters));
    }
}
