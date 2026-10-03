<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // DOKU Checkout (non-SNAP). Client ID and Secret Key from DOKU Back Office > Integration > API Keys.
    'doku' => [
        // false: no payment links; staff confirm orders and record payments manually (transfer, cash).
        'enabled' => (bool) env('DOKU_ENABLED', false),
        'client_id' => env('DOKU_CLIENT_ID'),
        'secret_key' => env('DOKU_SECRET_KEY'),
        'is_production' => (bool) env('DOKU_IS_PRODUCTION', false),
        'timeout' => (int) env('DOKU_TIMEOUT', 20),
        // qris: our own payment page with a QRIS from the DOKU QRIS Direct API (SNAP). checkout: DOKU's hosted Checkout page.
        // demo: the same payment page with a sample QR and a "simulate payment" button (no DOKU; for demos only, never on the live site).
        'mode' => env('DOKU_MODE', 'qris'),
        'qris_merchant_id' => env('DOKU_QRIS_MERCHANT_ID'),
        'qris_terminal_id' => env('DOKU_QRIS_TERMINAL_ID'),
        'qris_postal_code' => env('DOKU_QRIS_POSTAL_CODE', '80361'),
        // SNAP: our RSA private key (its public key is uploaded to DOKU) and, if DOKU issues a separate one, the SNAP client secret.
        'snap_private_key_path' => env('DOKU_SNAP_PRIVATE_KEY_PATH') ?: storage_path('app/private/doku-snap-private.pem'),
        'snap_client_secret' => env('DOKU_SNAP_CLIENT_SECRET'),
        'snap_token_path' => env('DOKU_SNAP_TOKEN_PATH', '/authorization/v1/access-token/b2b'),
    ],

    // Filled in once Erzap sends API documentation and sandbox credentials. Paths are placeholders until then.
    'erzap' => [
        // OLZAP "simpan_pesanan_penjualan": base URL like https://<domain_erzap>:4443, token sent in the body as token_erzap.
        'enabled' => (bool) env('ERZAP_ENABLED', false),
        'base_url' => env('ERZAP_BASE_URL'),
        'token' => env('ERZAP_TOKEN'),
        'order_path' => env('ERZAP_ORDER_PATH', '/apis/simpan_pesanan_penjualan'),
        // Receiving outlet when an outlet has no Erzap outlet ID in Mapping, and the sales user recorded on every order.
        'default_outlet_id' => env('ERZAP_DEFAULT_OUTLET_ID'),
        'sales_user_id' => env('ERZAP_SALES_USER_ID'),
        'timeout' => (int) env('ERZAP_TIMEOUT', 20),
        // false sends "kode": null so Erzap uses its own order numbering.
        'send_order_code' => (bool) env('ERZAP_SEND_ORDER_CODE', true),
    ],

    // Bearer tokens for the read-only REST API (comma separated). Empty means the protected endpoints are closed.
    'api' => [
        'tokens' => array_filter(array_map('trim', explode(',', (string) env('API_TOKENS', '')))),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
