<?php

namespace Modules\Settings;

class SettingsRegistry
{
    public const DEFINITIONS = [
        'app.name' => ['type' => 'string', 'default' => 'Core R', 'rules' => ['string', 'max:100'], 'public' => true, 'editable' => true, 'description' => 'settings.app_name'],
        'app.locale' => ['type' => 'string', 'default' => 'en', 'rules' => ['string', 'max:10'], 'public' => true, 'editable' => true, 'description' => 'settings.app_locale'],
        'app.timezone' => ['type' => 'string', 'default' => 'Asia/Jakarta', 'rules' => ['string', 'timezone'], 'public' => true, 'editable' => true, 'description' => 'settings.app_timezone'],
        'auth.registration_enabled' => ['type' => 'boolean', 'default' => false, 'rules' => ['boolean'], 'public' => true, 'editable' => true, 'description' => 'settings.registration_enabled'],
        'auth.email_verification_required' => ['type' => 'boolean', 'default' => true, 'rules' => ['boolean'], 'public' => true, 'editable' => true, 'description' => 'settings.email_verification_required'],
        'auth.token_expiration_days' => ['type' => 'integer', 'default' => 30, 'rules' => ['integer', 'min:1', 'max:365'], 'public' => false, 'editable' => true, 'description' => 'settings.token_expiration_days'],
        'auth.max_login_attempts' => ['type' => 'integer', 'default' => 5, 'rules' => ['integer', 'min:1', 'max:20'], 'public' => false, 'editable' => true, 'description' => 'settings.max_login_attempts'],
        'files.max_upload_mb' => ['type' => 'integer', 'default' => 10, 'rules' => ['integer', 'min:1', 'max:100'], 'public' => false, 'editable' => true, 'description' => 'settings.max_upload_mb'],
        'files.allowed_mime_types' => ['type' => 'array', 'default' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain'], 'rules' => ['array', 'min:1', 'max:10'], 'public' => false, 'editable' => true, 'description' => 'settings.allowed_mime_types'],
        'realtime.event_retention_days' => ['type' => 'integer', 'default' => 7, 'rules' => ['integer', 'min:1', 'max:90'], 'public' => false, 'editable' => true, 'description' => 'settings.event_retention_days'],
        'ops.failed_job_retention_hours' => ['type' => 'integer', 'default' => 168, 'rules' => ['integer', 'min:1', 'max:2160'], 'public' => false, 'editable' => true, 'description' => 'settings.failed_job_retention_hours'],
        'api.idempotency_ttl_hours' => ['type' => 'integer', 'default' => 24, 'rules' => ['integer', 'min:1', 'max:168'], 'public' => false, 'editable' => true, 'description' => 'settings.idempotency_ttl_hours'],
    ];
}
