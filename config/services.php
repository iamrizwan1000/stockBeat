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

    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
    ],

    'ebay' => [
        'env' => env('EBAY_ENV', 'sandbox'),
        'app_id' => env('EBAY_APP_ID'),
        'cert_id' => env('EBAY_CERT_ID'),
        'ru_name' => env('EBAY_RU_NAME'),
    ],

    'etsy' => [
        'keystring' => env('ETSY_KEYSTRING'),
        'shared_secret' => env('ETSY_SHARED_SECRET'),
    ],

    'amazon' => [
        // LWA (Login with Amazon) app credentials, issued when registering
        // the SP-API application in Seller Central's Developer Console
        // (Plan §7.5) — used for both the OAuth authorization-code exchange
        // (`AmazonAdapter::completeConnection()`) and per-connection
        // refresh-token exchanges (`refreshAuth()`).
        'client_id' => env('AMAZON_CLIENT_ID'),
        'client_secret' => env('AMAZON_CLIENT_SECRET'),

        // The SP-API "Application ID" from that same Developer Console
        // listing — distinct from `client_id` above. This is what appears
        // in the Seller Central authorization *consent* URL
        // (`authorizationUrl()`); `client_id`/`client_secret` are only ever
        // used against the LWA token endpoint, never the consent URL.
        'app_id' => env('AMAZON_APP_ID'),

        // Long-term IAM user credentials used *only* to call STS AssumeRole
        // (see AmazonAdapter::assumeRole()) — SP-API requires every
        // data-plane request signed with AWS SigV4 using the *temporary*
        // credentials that call returns, not these directly (Plan §7.5:
        // "AWS SigV4"; §15.2's role_arn note).
        'aws_access_key_id' => env('AMAZON_AWS_ACCESS_KEY_ID'),
        'aws_secret_access_key' => env('AMAZON_AWS_SECRET_ACCESS_KEY'),
        'aws_region' => env('AMAZON_AWS_REGION', 'us-east-1'),
        'role_arn' => env('AMAZON_ROLE_ARN'),

        // SP-API is split into NA/EU/FE regional endpoints (Plan §7.5
        // gotcha: "multi-marketplace... separate endpoints") — only NA is
        // wired up as a default; a seller operating in multiple regions
        // needs one store connection per region, same as every other
        // adapter's one-connection-per-store model.
        'region' => env('AMAZON_SPAPI_REGION', 'na'),
        'marketplace_id' => env('AMAZON_MARKETPLACE_ID', 'ATVPDKIKX0DER'), // US marketplace
    ],

    'tiktok_shop' => [
        // Partner Center "App Key"/"App Secret" for a registered TikTok Shop
        // app (Plan §7.6) — used for both the OAuth authorization-code
        // exchange (`TikTokAdapter::completeConnection()`) and the app-level
        // request signing every Partner API call requires (see
        // `TikTokRequestSigner`).
        'app_key' => env('TIKTOK_SHOP_APP_KEY'),
        'app_secret' => env('TIKTOK_SHOP_APP_SECRET'),

        // Some Partner API surfaces (notably the authorization URL for
        // non-US regions) key off a "Service ID" issued alongside the
        // app registration rather than the app_key alone — verify at build
        // time whether this connection's own marketplace needs it; left
        // null/unused otherwise.
        'service_id' => env('TIKTOK_SHOP_SERVICE_ID'),

        // TikTok Shop's Partner API is region-sharded (similar gotcha to
        // Amazon's NA/EU/FE split, Plan §7.5) — only the default US/global
        // "open-api" host is wired up; a seller on another region's Shop
        // would need its own connection + host, same one-connection-per-
        // region model as `AmazonAdapter`.
        'region' => env('TIKTOK_SHOP_REGION', 'us'),
    ],

];
