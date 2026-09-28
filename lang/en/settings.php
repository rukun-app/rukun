<?php

return [
    'app_name' => 'Application name shown to clients.',
    'app_locale' => 'Default application locale.',
    'app_timezone' => 'Default IANA timezone.',
    'registration_enabled' => 'Allow public user registration.',
    'email_verification_required' => 'Require email verification before login.',
    'token_expiration_days' => 'Lifetime of newly issued API tokens in days.',
    'max_login_attempts' => 'Maximum login attempts before rate limiting.',
    'max_upload_mb' => 'Maximum file upload size in megabytes.',
    'allowed_mime_types' => 'MIME types allowed for file uploads.',
    'event_retention_days' => 'Number of days durable realtime events remain available for polling.',
    'failed_job_retention_hours' => 'Number of hours failed queue jobs remain available for inspection.',
    'idempotency_ttl_hours' => 'Number of hours successful idempotent responses remain replayable.',
];
