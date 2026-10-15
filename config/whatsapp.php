<?php

/*
|--------------------------------------------------------------------------
| WhatsApp — Meta's Cloud API
|--------------------------------------------------------------------------
|
| Used for exactly one thing today: a six-digit sign-in code. Meta requires
| codes to go out as an approved AUTHENTICATION template with a copy-code
| button; free-text messages are refused for this purpose. The template's
| name and language are set here; its body is Meta's fixed wording, and the
| code is the one parameter. The WhatsApp agent (spec §7.16) builds on this.
|
| Secrets come from the environment only.
|
*/

return [
    'enabled' => (bool) env('WHATSAPP_ENABLED', true),

    'base_url' => env('WHATSAPP_API_BASE', 'https://graph.facebook.com'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),

    // The sender: the "Phone number ID" from WhatsApp Manager (not the number).
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),

    // A permanent System User token with whatsapp_business_messaging.
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),

    // The approved authentication template and its language code.
    'auth_template' => env('WHATSAPP_AUTH_TEMPLATE', 'sign_in_code'),
    'auth_template_language' => env('WHATSAPP_AUTH_TEMPLATE_LANG', 'en'),

    // Body parameters AFTER the code, in order, for a template whose body has more
    // than the code in it — e.g. "OTP Code: {{1}}. This is your OTP code for {{2}}."
    // wants "Login" as its second. Comma-separated. Empty for Meta's stock template.
    'auth_template_extra_params' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WHATSAPP_AUTH_TEMPLATE_EXTRA_PARAMS', ''))
    ), fn ($v) => $v !== '')),

    // 'body_and_button' for Meta's authentication template (code in the body and on
    // a copy-code button); 'body_only' for a plain template with the code as its one
    // parameter. The sender tries the other when Meta rejects this one and remembers
    // the shape that worked, so this is a starting point, not a hard rule.
    'auth_template_shape' => env('WHATSAPP_AUTH_TEMPLATE_SHAPE', 'body_and_button'),

    'timeout' => (int) env('WHATSAPP_TIMEOUT', 15),

    // The country assumed for a number typed without one (PhoneNumber::normalise).
    // Empty refuses such numbers instead of guessing.
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '20'),
];
