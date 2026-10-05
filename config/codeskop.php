<?php

return [
    // The project's public key (cs_live_pk_… / cs_test_pk_…). Never the secret key.
    'api_key' => env('CODESKOP_API_KEY'),
    'endpoint' => env('CODESKOP_ENDPOINT', 'https://api.codeskop.com'),
    'environment' => env('CODESKOP_ENVIRONMENT', env('APP_ENV', 'production')),
    'release' => env('CODESKOP_RELEASE'),
    'enabled' => env('CODESKOP_ENABLED', true),

    // Incoming requests (by route template) and outgoing calls (Laravel HTTP client / Guzzle).
    'capture_requests' => true,
    'capture_outgoing' => true,
    'ignore_routes' => ['/health*', '/healthz', '/up', '/metrics', '/favicon.ico'],
    'ignore_exceptions' => [],

    // Attach the authenticated user's ID (never the email) when the guard already resolved it.
    'send_user_id' => true,

    // API Trust (add-on): override how a consumer is identified, e.g. fn ($request) => $request->user()?->client_id.
    'api_trust_resolver' => null,
    'trust_proxy' => null,

    'flush_timeout' => 2.0,
    'debug' => env('CODESKOP_DEBUG', false),
];
