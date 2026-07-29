<?php

return [
    'sms' => [
        // These limits are enforced server-side. A campaign may choose a lower
        // rate, but never a higher one.
        'provider_rate_per_second' => (int) env('SMS_PROVIDER_RATE_PER_SECOND', 5),
        'platform_rate_per_second' => (int) env('SMS_PLATFORM_RATE_PER_SECOND', 20),

        // A large campaign receives exclusive use of its provider credentials.
        'large_campaign_threshold' => (int) env('SMS_LARGE_CAMPAIGN_THRESHOLD', 10000),

        // Keep the queue shallow: at 5 TPS this is roughly five seconds of work.
        'dispatch_buffer' => (int) env('SMS_DISPATCH_BUFFER', 25),
        'audience_chunk_size' => (int) env('SMS_AUDIENCE_CHUNK_SIZE', 2000),
        'claim_timeout_seconds' => (int) env('SMS_CLAIM_TIMEOUT_SECONDS', 180),
        'max_inline_rate_wait_microseconds' => (int) env('SMS_MAX_INLINE_RATE_WAIT_MICROSECONDS', 5000000),

        // Initial attempt plus five controlled retries.
        'retry_delays' => [3, 15, 60, 300, 900],
        'systemic_failure_pause_threshold' => (int) env('SMS_SYSTEMIC_FAILURE_PAUSE_THRESHOLD', 3),
    ],
];
