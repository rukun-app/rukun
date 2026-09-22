<?php

namespace Modules\Settings;

class SettingsRegistry
{
    public const DEFINITIONS = [
        'app.name' => ['type' => 'string', 'default' => 'Core R', 'rules' => ['string', 'max:100'], 'public' => true, 'editable' => true, 'description' => 'Application name shown to clients.'],
        'app.locale' => ['type' => 'string', 'default' => 'en', 'rules' => ['string', 'max:10'], 'public' => true, 'editable' => true, 'description' => 'Default application locale.'],
        'app.timezone' => ['type' => 'string', 'default' => 'Asia/Jakarta', 'rules' => ['string', 'timezone'], 'public' => true, 'editable' => true, 'description' => 'Default IANA timezone.'],
        'auth.registration_enabled' => ['type' => 'boolean', 'default' => false, 'rules' => ['boolean'], 'public' => true, 'editable' => true, 'description' => 'Allow public user registration.'],
        'auth.email_verification_required' => ['type' => 'boolean', 'default' => true, 'rules' => ['boolean'], 'public' => true, 'editable' => true, 'description' => 'Require email verification before login.'],
        'auth.token_expiration_days' => ['type' => 'integer', 'default' => 30, 'rules' => ['integer', 'min:1', 'max:365'], 'public' => false, 'editable' => true, 'description' => 'Lifetime of newly issued API tokens in days.'],
        'auth.max_login_attempts' => ['type' => 'integer', 'default' => 5, 'rules' => ['integer', 'min:1', 'max:20'], 'public' => false, 'editable' => true, 'description' => 'Maximum login attempts before rate limiting.'],
    ];
}
