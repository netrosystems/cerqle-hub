<?php

return [
    'credits' => [
        // Shadow mode records usage but does not block. Enable only after the
        // reconciliation report has shown that every production path is metered.
        'enforced' => (bool) env('AI_CREDITS_ENFORCED', false),
        'reservation_ttl_minutes' => 10,
        'rates_version' => '2026-09-01',
        'rates' => [
            'rag_reply' => 1,
            'email_subject' => 1,
            'short_rewrite' => 1,
            'automation_ai_step' => 1,
            'email_compose' => 2,
            'social_single_generate' => 2,
            'automation_workflow_generate' => 5,
            'social_plan_generate' => 5,
        ],
    ],
    'managed' => [
        'provider' => 'openai',
        'routine_model' => env('MANAGED_AI_ROUTINE_MODEL', 'gpt-5-nano'),
        'complex_model' => env('MANAGED_AI_COMPLEX_MODEL', 'gpt-5-mini'),
        'embedding_model' => env('MANAGED_AI_EMBED_MODEL', 'text-embedding-3-small'),
        // Version alongside the credit rate table when provider pricing changes.
        // Values are micro-USD per one million tokens.
        'pricing_microusd_per_million' => [
            'gpt-5-nano' => ['input' => 50000, 'output' => 400000],
            'gpt-5-mini' => ['input' => 250000, 'output' => 2000000],
        ],
        'complex_features' => [
            'email_compose',
            'social_single_generate',
            'automation_workflow_generate',
            'social_plan_generate',
        ],
    ],
    'abuse' => [
        'free_requests_per_minute' => 10,
        'paid_requests_per_minute' => 60,
        'free_concurrency' => 2,
        'paid_concurrency' => 10,
    ],
];
