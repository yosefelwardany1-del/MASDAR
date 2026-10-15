<?php

/*
| Sign in with Google (staff). OFF by default: it switches on once Al-Quds has a Google
| Workspace domain (OPEN). Until then sign-in is by password only, and passwords are
| never retired by default. The rules live in App\Services\Auth\SsoSignIn.
*/

return [

    // Master switch. Off = no button on the login page and the Google routes 404.
    'enabled' => (bool) env('SSO_GOOGLE_ENABLED', false),

    // The OAuth client (Google Cloud → Clients → Web application). Its authorised
    // redirect URI must be {APP_URL}/auth/google/callback. Env only — never in the database.
    'client_id' => env('GOOGLE_SSO_CLIENT_ID'),
    'client_secret' => env('GOOGLE_SSO_CLIENT_SECRET'),

    // ONLY these domains may sign in with Google, and only these may be linked as a
    // second address. Any other Google account is refused before the system looks it up.
    'allowed_domains' => array_values(array_filter(array_map(
        fn ($d) => strtolower(trim($d)),
        explode(',', (string) env('SSO_ALLOWED_DOMAINS', ''))
    ))),

    // Keep a Google sign-in across sessions (the remember cookie, auth.guards.web.remember).
    'remember' => (bool) env('SSO_REMEMBER', true),

    // When true, a password is no longer accepted for an address on the allowed domains —
    // Google is the only way in — except the break-glass addresses below. Addresses on
    // any other domain keep their passwords.
    'passwords_retired' => (bool) env('SSO_PASSWORDS_RETIRED', false),

    // Addresses that keep a working password even after passwords are retired, so a
    // Google outage or a wrong allow-list cannot lock everybody out. Comma-separated.
    'breakglass_emails' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim($e)),
        explode(',', (string) env('SSO_BREAKGLASS_EMAILS', ''))
    ))),

    // Google endpoints, here so a test can fake them and nothing hard-codes a host twice.
    'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_url' => 'https://oauth2.googleapis.com/token',
    'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',

    // Identity only — never calendar, mail or drive.
    'scopes' => 'openid email profile',

];
