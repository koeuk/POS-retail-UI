<?php

use App\Payments\Gateways\BakongGateway;
use App\Payments\Gateways\ManualGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | QR payment providers
    |--------------------------------------------------------------------------
    |
    | Which provider the till uses is a shop setting (Settings -> Payments),
    | not a deploy-time choice. What lives here is the plumbing each provider
    | needs that an admin should never have to type: endpoints and timeouts.
    |
    | Adding a provider is one class implementing App\Payments\QrGateway plus
    | one entry in `providers` — see docs/payments.md.
    |
    */

    'providers' => [
        // Static shop QR, confirmed by the cashier. Works with no API access
        // and no internet — the default, and the offline fallback for all.
        'manual' => ManualGateway::class,

        // National Bank of Cambodia's Bakong Open API: dynamic KHQR per sale,
        // verified automatically by transaction hash.
        'bakong' => BakongGateway::class,
    ],

    /* How long a dynamic QR stays payable before the till asks for a new one. */
    'qr_ttl_seconds' => (int) env('PAYMENTS_QR_TTL', 180),

    'bakong' => [
        'endpoints' => [
            'production' => env('BAKONG_API_URL', 'https://api-bakong.nbc.gov.kh'),
            'sandbox' => env('BAKONG_SANDBOX_URL', 'https://sit-api-bakong.nbc.gov.kh'),
        ],

        /*
         * The developer token from the Bakong portal. Normally entered on the
         * Payments settings screen (stored encrypted); this env value is only
         * a fallback for deployments that keep secrets out of the database.
         */
        'token' => env('BAKONG_TOKEN'),

        'timeout' => (int) env('BAKONG_TIMEOUT', 8),

        /*
         * Checks allowed per day on your Bakong tier (the free tier is 100).
         * Past it the till stops asking and falls back to hand confirmation
         * rather than being refused by Bakong mid-sale.
         */
        'daily_limit' => (int) env('BAKONG_DAILY_LIMIT', 100),
    ],

];
