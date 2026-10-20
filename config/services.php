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
];
