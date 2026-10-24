<?php

return [
    'delivery_mode' => env('DELIVERY_MODE', 'preview'),
    'sprint_sms' => [
        'url' => env('SPRINT_SMS_URL', 'https://api.sprintsmsservice.com/api/SendSMS'),
        'api_id' => env('SPRINT_SMS_API_ID'),
        'api_password' => env('SPRINT_SMS_API_PASSWORD'),
        'sender_id' => env('SPRINT_SMS_SENDER_ID'),
    ],
    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
        'content_sid' => env('TWILIO_WHATSAPP_CONTENT_SID'),
    ],
    'snippe' => [
        'key' => env('SNIPPE_KEY'),
        'webhook_secret' => env('SNIPPE_WEBHOOK_SECRET'),
        'webhook_base_url' => env('SNIPPE_WEBHOOK_BASE_URL'),
        'base_url' => env('SNIPPE_BASE_URL', 'https://api.snippe.sh'),
        'timeout' => env('SNIPPE_TIMEOUT', 30),
    ],
    'nominatim' => [
        // Nominatim is used only for explicit user-submitted searches, never
        // keystroke autocomplete. Point this at a managed/self-hosted endpoint
        // if higher volume or different usage terms are required.
        'url' => env('NOMINATIM_SEARCH_URL', 'https://nominatim.openstreetmap.org/search'),
        'user_agent' => env('NOMINATIM_USER_AGENT', sprintf(
            '%s/1.0 (%s)',
            env('APP_NAME', 'Okesheni'),
            env('APP_URL', 'http://localhost:8000'),
        )),
        'contact_email' => env('NOMINATIM_CONTACT_EMAIL'),
        'country_codes' => env('NOMINATIM_COUNTRY_CODES', 'tz'),
        'language' => env('NOMINATIM_LANGUAGE', 'en'),
        'timeout' => env('NOMINATIM_TIMEOUT', 5),
        'cache_ttl' => env('NOMINATIM_CACHE_TTL', 86400),
        'max_results' => env('NOMINATIM_MAX_RESULTS', 5),
    ],
    'map_tiles' => [
        'url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    ],
];
