<?php
return [
    'twilio' => [
        'sid' => $_ENV['TWILIO_SID'] ?? '',
        'token' => $_ENV['TWILIO_TOKEN'] ?? '',
        'from' => $_ENV['TWILIO_FROM'] ?? ''
    ],
    'google_maps' => [
        'api_key' => $_ENV['GOOGLE_MAPS_API_KEY'] ?? ''
    ],
    'email' => [
        'host' => $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com',
        'port' => $_ENV['SMTP_PORT'] ?? 587,
        'user' => $_ENV['SMTP_USER'] ?? '',
        'pass' => $_ENV['SMTP_PASS'] ?? '',
        'from' => $_ENV['SMTP_FROM'] ?? 'noreply@savantmotors.com'
    ]
];