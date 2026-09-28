<?php

return [
    'default' => env('PAYMENT_GATEWAY', 'midtrans'),
    'redirect_base_url' => env('PAYMENT_REDIRECT_BASE_URL', env('APP_URL', 'http://localhost')),
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'production' => (bool) env('MIDTRANS_PRODUCTION', false),
        'snap_url' => env('MIDTRANS_SNAP_URL'),
        'api_url' => env('MIDTRANS_API_URL'),
    ],
];
