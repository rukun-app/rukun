<?php

return [
    'rate_limits' => [
        'auth_register' => max(1, (int) env('RATE_LIMIT_AUTH_REGISTER', 5)),
        'auth_login' => max(1, (int) env('RATE_LIMIT_AUTH_LOGIN', 20)),
        'auth_recovery' => max(1, (int) env('RATE_LIMIT_AUTH_RECOVERY', 3)),
        'files_upload' => max(1, (int) env('RATE_LIMIT_FILES_UPLOAD', 20)),
        'events_poll' => max(1, (int) env('RATE_LIMIT_EVENTS_POLL', 120)),
        'notifications_mutate' => max(1, (int) env('RATE_LIMIT_NOTIFICATIONS_MUTATE', 60)),
        'admin_sensitive' => max(1, (int) env('RATE_LIMIT_ADMIN_SENSITIVE', 30)),
        'payment_webhooks' => max(1, (int) env('RATE_LIMIT_PAYMENT_WEBHOOKS', 120)),
    ],
];
