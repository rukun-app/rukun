<?php

return [
    'default' => env('PAYMENT_GATEWAY', 'midtrans'),
    'midtrans' => [
        'enabled' => (bool) env('MIDTRANS_ENABLED', false),
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'production' => (bool) env('MIDTRANS_PRODUCTION', false),
        'snap_url' => env('MIDTRANS_SNAP_URL'),
        'api_url' => env('MIDTRANS_API_URL'),
        'timeout' => max(1, (int) env('MIDTRANS_TIMEOUT', 10)),
    ],
];
