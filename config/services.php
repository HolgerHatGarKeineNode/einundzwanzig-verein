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

    'relay' => env('NOSTR_RELAY'),
    'nostr' => env('NOSTR_P'),

    /*
     * The key that signs the association's direct messages (NIP-17 seal) and
     * authenticates to relays that demand it (NIP-42). Deliberately not
     * NOSTR_P: that key presents itself publicly as "Einundzwanzig Portal"
     * and signs the fee events. nsec or 64 hex characters.
     *
     * Read in exactly one place, App\Services\Nostr\NotificationKey.
     */
    'nostr_notification_key' => env('NOSTR_NOTIFICATION_KEY'),

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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'btc_pay' => [
        'base_url' => rtrim((string) env('BTC_PAY_BASE_URL', 'https://pay.einundzwanzig.space'), '/'),
        'api_key' => env('BTC_PAY_API_KEY'),
        'store_id' => env('BTC_PAY_STORE_ID', '98PF86BoMd3C8P1nHHyFdoeznCwtcm5yehcAgoCYDQ2a'),
        'webhook_secret' => env('BTC_PAY_WEBHOOK_SECRET'),
    ],

];
