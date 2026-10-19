<?php

return [
    'email_enabled' => (bool) env('CALENDARING_EMAIL_ENABLED', false),

    'digest' => [
        'enabled' => (bool) env('CALENDARING_DIGEST_ENABLED', false),
        'time' => env('CALENDARING_DIGEST_TIME', '08:00'),
        'timezone' => env('CALENDARING_DIGEST_TIMEZONE', 'UTC'),
    ],
];
