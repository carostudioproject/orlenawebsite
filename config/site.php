<?php

return [
    'indexable' => env('SITE_INDEXABLE', false),
    'whatsapp_number' => env('WHATSAPP_NUMBER', '6282145809558'),
    // An order sent sooner than this after the form was opened is treated as a bot (0 in tests).
    'checkout_min_seconds' => (int) env('CHECKOUT_MIN_SECONDS', 5),
];
