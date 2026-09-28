<?php

return [
    'default' => env('PAYMENT_GATEWAY', 'midtrans'),
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'production' => (bool) env('MIDTRANS_PRODUCTION', false),
        'snap_url' => env('MIDTRANS_SNAP_URL'),
        'api_url' => env('MIDTRANS_API_URL'),
    ],
];
