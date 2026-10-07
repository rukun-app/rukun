<?php

namespace Modules\Notifications;

class NotificationRegistry
{
    public const CATEGORIES = [
        'civic' => ['database' => true, 'mail' => false, 'locked' => ['mail']],
        'security' => ['database' => true, 'mail' => true, 'locked' => ['database', 'mail']],
        'account' => ['database' => true, 'mail' => true, 'locked' => ['database']],
        'system' => ['database' => true, 'mail' => false, 'locked' => []],
    ];
}
