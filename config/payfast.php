<?php

return [
    'sandbox' => env('TESTING_MODE') === 'true' || env('APP_ENV') !== 'production',
    'merchant_id' => env('PAYFAST_MERCHANT_ID'),
    'merchant_key' => env('PAYFAST_MERCHANT_KEY'),
    'passphrase' => env('PAYFAST_PASSPHRASE', ''),
    'return_url' => env('PAYFAST_RETURN_URL', ''),
    'cancel_url' => env('PAYFAST_CANCEL_URL', ''),
    'notify_url' => env('PAYFAST_NOTIFY_URL', ''),

    'subscriptions_api_base' => env('PAYFAST_SUBSCRIPTIONS_API_BASE', 'https://api.payfast.co.za'),

    'route_prefix' => env('PAYFAST_ROUTE_PREFIX', 'payfast'),
    'route_middleware' => ['api'],
];
