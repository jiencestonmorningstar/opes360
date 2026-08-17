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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * The ClamAV daemon UploadGate streams uploads to. In services.php rather
     * than a config file of its own because that is exactly what clamd is — a
     * third-party service the app talks to over a socket — and a one-key file
     * would exist only to hold this line. Read via config(), never env():
     * a raw env() call outside config/ returns null once `config:cache` has
     * run, which is every production deploy, and would switch scanning off
     * precisely where it matters.
     *
     *   CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl   (the VPS)
     *   CLAMAV_SOCKET=tcp://127.0.0.1:3310
     *
     * Leave it unset anywhere clamd does not run; the gate degrades to its
     * sniff checks.
     */
    'clamav' => [
        'socket' => env('CLAMAV_SOCKET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * MTN Mobile Money Collections API (momodeveloper.mtn.com). api_user and
     * api_key are the Collections API user provisioned in the MTN developer
     * portal (or the MoMo sandbox self-signup) — not a person's login.
     * environment is the value sent as X-Target-Environment: "sandbox" for
     * testing, or the operator's production target (e.g. "mtncameroon") once
     * MTN has approved the merchant.
     */
    'mtn_momo' => [
        'base_url' => env('MTN_MOMO_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'),
        'environment' => env('MTN_MOMO_ENVIRONMENT', 'sandbox'),
        'subscription_key' => env('MTN_MOMO_SUBSCRIPTION_KEY'),
        'api_user' => env('MTN_MOMO_API_USER'),
        'api_key' => env('MTN_MOMO_API_KEY'),
        'default_country_code' => env('MTN_MOMO_DEFAULT_COUNTRY_CODE', '237'),
    ],

    /*
     * Orange Money Web Payment API (developer.orange.com/apis/om-webpay).
     * client_id/client_secret authenticate against the Orange Developer API
     * gateway; merchant_key is issued separately by Orange Money for the
     * merchant account and identifies who gets paid.
     */
    'orange_money' => [
        'base_url' => env('ORANGE_MONEY_BASE_URL', 'https://api.orange.com'),
        'oauth_path' => env('ORANGE_MONEY_OAUTH_PATH', '/oauth/v3/token'),
        'client_id' => env('ORANGE_MONEY_CLIENT_ID'),
        'client_secret' => env('ORANGE_MONEY_CLIENT_SECRET'),
        'merchant_key' => env('ORANGE_MONEY_MERCHANT_KEY'),
        'country' => env('ORANGE_MONEY_COUNTRY', 'cm'),
        'lang' => env('ORANGE_MONEY_LANG', 'fr'),
    ],

];
