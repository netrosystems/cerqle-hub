<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

    'sms' => [
        // These limits are enforced server-side and shared by every campaign
        // using the same provider credentials. Step 1 stays deliberately slow
        // so the first 100 recipients validate the route before bulk delivery.
        'safety_rate_per_second' => (int) env('SMS_SAFETY_RATE_PER_SECOND', 5),
        'provider_rate_per_second' => (int) env('SMS_PROVIDER_RATE_PER_SECOND', 180),
        'platform_rate_per_second' => (int) env('SMS_PLATFORM_RATE_PER_SECOND', 180),

        // A large campaign receives exclusive use of its provider credentials.
        'large_campaign_threshold' => (int) env('SMS_LARGE_CAMPAIGN_THRESHOLD', 10000),

        // Keep approximately two seconds of high-volume work claimed. The
        // gateway-wide limiter remains the authority on actual send starts.
        'dispatch_buffer' => (int) env('SMS_DISPATCH_BUFFER', 360),
        // This value documents the number of persistent `broadcast` queue
        // processes provisioned by deployment. It is displayed as a capacity
        // diagnostic; Laravel cannot reliably discover Supervisor processes.
        'broadcast_worker_count' => (int) env('SMS_BROADCAST_WORKER_COUNT', 48),
        'audience_chunk_size' => (int) env('SMS_AUDIENCE_CHUNK_SIZE', 2000),
        'claim_timeout_seconds' => (int) env('SMS_CLAIM_TIMEOUT_SECONDS', 180),
        'max_inline_rate_wait_microseconds' => (int) env('SMS_MAX_INLINE_RATE_WAIT_MICROSECONDS', 5000000),

        // Initial attempt plus five controlled retries.
        'retry_delays' => [3, 15, 60, 300, 900],
        'systemic_failure_pause_threshold' => (int) env('SMS_SYSTEMIC_FAILURE_PAUSE_THRESHOLD', 3),
    ],
];
