<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'revenuecat' => [
        'webhook_secret' => env('REVENUECAT_WEBHOOK_SECRET'),
    ],

    'inbound_email' => [
        // Shared-secret boundary for /hooks/email-inbound (Plan §4.5/§4.9/§7.7),
        // same pattern as revenuecat.webhook_secret — whatever inbound-email
        // provider is wired up (SES+Lambda, Mailgun, Postmark) must be
        // configured to send this same value. Not a per-request signature,
        // since no such provider is actually connected yet.
        'webhook_secret' => env('INBOUND_EMAIL_WEBHOOK_SECRET'),

        // The domain plus-addressed Reply-To addresses are built against
        // (e.g. thread+17@{domain}) — see `SendInboxMessageAction`. No
        // inbound-parse provider is actually connected yet, so this is a
        // placeholder until one is (real SES/Mailgun/Postmark domain).
        'domain' => env('INBOUND_EMAIL_DOMAIN', 'mail.stockbeat.app'),
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
    ],

];
