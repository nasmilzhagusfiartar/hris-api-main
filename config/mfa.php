<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Global MFA Toggle
    |--------------------------------------------------------------------------
    */
    'enabled' => filter_var(env('MFA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Roles Requiring Mandatory MFA Enrollment
    |--------------------------------------------------------------------------
    | Comma-separated role names, e.g. "admin,security". Empty = optional.
    */
    'enforce_roles' => array_values(array_filter(
        array_map(
            static fn (string $role): string => strtolower(trim($role)),
            explode(',', (string) env('MFA_ENFORCE_ROLES', ''))
        ),
        static fn (string $role): bool => $role !== ''
    )),

    /*
    |--------------------------------------------------------------------------
    | Authenticator Issuer Name
    |--------------------------------------------------------------------------
    */
    'issuer' => env('MFA_ISSUER', 'HIP HRIS'),

    /*
    |--------------------------------------------------------------------------
    | Verification & Security Parameters
    |--------------------------------------------------------------------------
    */
    'window' => 1, // +/- 1 period (+/- 30 seconds)
    'token_ttl_seconds' => 300, // 5 minutes
    'setup_ttl_seconds' => 900, // 15 minutes
    'max_attempts' => 5,
    'lockout_minutes' => 15,
    'recovery_codes_count' => 8,
];
