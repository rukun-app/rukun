<?php

return [
    'app_name' => env('APP_NAME'),
    'app_locale' => env('APP_LOCALE'),
    'app_timezone' => env('APP_TIMEZONE'),
    'registration_enabled' => env('AUTH_REGISTRATION_ENABLED'),
    'email_verification_required' => env('AUTH_EMAIL_VERIFICATION_REQUIRED'),
    'token_expiration_days' => env('AUTH_TOKEN_EXPIRATION_DAYS'),
    'max_login_attempts' => env('AUTH_MAX_LOGIN_ATTEMPTS'),
    'max_upload_mb' => env('FILES_MAX_UPLOAD_MB'),
    'allowed_mime_types' => env('FILES_ALLOWED_MIME_TYPES')
        ? array_values(array_filter(array_map('trim', explode(',', env('FILES_ALLOWED_MIME_TYPES')))))
        : null,
    'event_retention_days' => env('REALTIME_EVENT_RETENTION_DAYS'),
    'failed_job_retention_hours' => env('OPS_FAILED_JOB_RETENTION_HOURS'),
    'idempotency_ttl_hours' => env('API_IDEMPOTENCY_TTL_HOURS'),
    'rate_limits' => [
        'auth_register' => env('RATE_LIMIT_AUTH_REGISTER'),
        'auth_login' => env('RATE_LIMIT_AUTH_LOGIN'),
        'auth_recovery' => env('RATE_LIMIT_AUTH_RECOVERY'),
        'files_upload' => env('RATE_LIMIT_FILES_UPLOAD'),
        'events_poll' => env('RATE_LIMIT_EVENTS_POLL'),
        'notifications_mutate' => env('RATE_LIMIT_NOTIFICATIONS_MUTATE'),
        'admin_sensitive' => env('RATE_LIMIT_ADMIN_SENSITIVE'),
        'payment_webhooks' => env('RATE_LIMIT_PAYMENT_WEBHOOKS'),
    ],
    'midtrans_enabled' => env('MIDTRANS_ENABLED'),
    'midtrans_timeout' => env('MIDTRANS_TIMEOUT'),
];
