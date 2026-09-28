<?php

return [
    'errors' => [
        'unauthenticated' => 'Unauthenticated.',
        'forbidden' => 'You do not have permission to perform this action.',
        'not_found' => 'Resource not found.',
        'validation' => 'Validation failed.',
        'method_not_allowed' => 'Method not allowed.',
        'rate_limited' => 'Too many requests.',
        'server' => 'Server error.',
        'request_failed' => 'Request failed.',
        'cursor_expired' => 'The event cursor has expired. Request a new cursor and refresh the affected resources.',
    ],
    'auth' => [
        'registration_disabled' => 'Registration is disabled.',
        'registered' => 'Registration successful. Verify your email before login.',
        'too_many_attempts' => 'Too many login attempts.',
        'invalid_credentials' => 'Invalid credentials.',
        'suspended' => 'Account is suspended.',
        'unverified' => 'Email address is not verified.',
        'password_changed' => 'Password changed. Other tokens were revoked.',
        'logged_out' => 'Logged out.',
        'tokens_revoked' => 'All tokens revoked.',
        'verification_sent' => 'If the account exists, a verification email has been sent.',
        'invalid_verification' => 'Invalid verification link.',
        'verified' => 'Email verified.',
        'reset_sent' => 'If the account exists, a reset link has been sent.',
    ],
    'files' => [
        'content_missing' => 'File content not found.',
        'attached' => 'Attached files must be detached before deletion.',
        'deletion_scheduled' => 'File deletion scheduled.',
    ],
    'access' => [
        'self_suspend' => 'You cannot suspend your own account.',
        'last_manager_suspend' => 'The last access manager cannot be suspended.',
        'self_roles' => 'You cannot remove all your own roles.',
        'last_manager_roles' => 'The last access manager must keep access management permission.',
        'role_conflict' => 'Role was changed by another request. Reload it and try again.',
        'role_in_use' => 'Role is assigned to users.',
        'role_deleted' => 'Role deleted.',
    ],
    'tokens' => [
        'not_found' => 'Token not found.',
        'revoked' => 'Token revoked.',
    ],
    'settings' => [
        'unknown' => 'Unknown setting keys: :keys',
    ],
];
