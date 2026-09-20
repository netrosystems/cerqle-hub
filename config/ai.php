<?php

return [
    'smart_bot' => [
        // Both switches are intentionally rollout-controlled. Configuration and
        // indexing can ship before business-aware routing is enabled in production.
        'business_aware_routing' => (bool) env('SMART_BOT_BUSINESS_AWARE_ROUTING', false),
        'hybrid_retrieval' => (bool) env('KB_HYBRID_RETRIEVAL_ENABLED', false),
        'confidence_threshold' => 0.72,
        'clarification_threshold' => 0.47,

        // Behaviour adoption switches (2026-09-20). Each is enabled and verified
        // on its own; with every one false the bot answers exactly as before.
        'retrieval_authority' => (bool) env('SMART_BOT_RETRIEVAL_AUTHORITY', false),
        'diagnostics' => (bool) env('SMART_BOT_DIAGNOSTICS', false),
        'conversational_turns' => (bool) env('SMART_BOT_CONVERSATIONAL_TURNS', false),
        'multilingual_handover' => (bool) env('SMART_BOT_MULTILINGUAL_HANDOVER', false),
        'channel_choice_fallback' => (bool) env('SMART_BOT_CHANNEL_CHOICE_FALLBACK', false),
        'no_agent_holding_reply' => (bool) env('SMART_BOT_NO_AGENT_HOLDING_REPLY', false),
        'bot_continues_after_unanswered_handoff' => (bool) env('SMART_BOT_CONTINUES_AFTER_HANDOFF', false),
        'choice_ttl_minutes' => 30,
        // Only the web widget renders buttons. Everywhere else the options have
        // to appear in the message body or the customer never sees them.
        'choice_channels' => [
            'webchat' => ['native' => true, 'max' => 4],
            'whatsapp' => ['native' => false, 'max' => 3],
            'messenger' => ['native' => false, 'max' => 3],
            'instagram' => ['native' => false, 'max' => 3],
            'email' => ['native' => false, 'max' => 3],
        ],
        'diagnostics_retention_days' => 90,

        // Retrieved evidence is unbounded today: max_context_chunks chunks of up
        // to 6000 characters each go verbatim into the prompt. These cap it.
        // Precedence rule: a per-bot value wins where set, the platform default
        // applies where the column is null.
        'context_chars' => 6000,
        'passage_chars' => 1800,
        'duplicate_overlap' => 0.75,

        // Language handling is deliberately open-ended: a customer may write in
        // any language, including one nobody anticipated. Intent is recognised
        // by comparing the message embedding with these English exemplars —
        // the embedding model is multilingual, so a seed like "hello" sits near
        // its equivalents in scripts we never enumerate. These are semantic
        // seeds, NOT translations, and no language list is shipped anywhere.
        'intent' => [
            'enabled' => (bool) env('SMART_BOT_MULTILINGUAL_INTENT', false),
            'threshold' => 0.62,
            'margin' => 0.04,
            'human_threshold' => 0.82,
            // Chit-chat is short. A long message is a question, whatever it
            // resembles, so these guards run before any similarity is computed.
            'max_chars' => 48,
            'max_words' => 6,
            // Bump to re-seed cached exemplar vectors after editing the lists.
            'version' => 1,
            'exemplars' => [
                'greeting' => ['hello', 'hi there', 'hey', 'good morning', 'good evening', 'greetings', 'hi, is anyone there?'],
                'thanks' => ['thank you', 'thanks a lot', 'much appreciated', 'thanks for your help', 'cheers, that helps'],
                'closing' => ['goodbye', 'bye for now', "that's all, thanks", 'nothing else', 'we are done here', 'have a good day'],
                'decline' => ['no thanks', 'no, not now', 'maybe later', 'not interested', "that won't be necessary"],
                'acceptance' => ['yes please', 'sure, go ahead', 'okay, do that', 'sounds good', 'yes, that works'],
                'wants_human' => ['can I talk to a person', 'I want to speak with a human agent', 'connect me to support staff', 'put me through to someone', 'I need to talk to a real representative'],
            ],
        ],

        // Short fixed strings the bot says on paths that cost nothing. The
        // English text is the seed; a translation is generated once per language
        // and cached, so no phrase table is ever shipped.
        'phrases' => [
            'version' => 1,
            'cache_days' => 90,
            'seeds' => [
                'greeting' => 'Hello! How can I help you today?',
                'thanks_ack' => "You're welcome! Is there anything else I can help with?",
                'closing' => 'Happy to help. Have a great day!',
                'decline_ack' => "No problem at all. I'm here whenever you need anything.",
                'clarify_prompt' => 'Could you tell me a little more about what you need, so I can find the right information?',
                'handoff_offer' => 'Would you like me to connect you with a team member?',
                'no_verified_info' => 'I do not have confirmed information about that yet.',
                'provider_error' => 'I could not complete that answer just now.',
                'no_agent_available' => "Our team is offline right now. I've passed this on and they'll reply here as soon as they're back.",
                'qr_tell_me_more' => 'Tell me more',
                'qr_talk_to_person' => 'Talk to a person',
                'qr_yes' => 'Yes',
                'qr_no' => 'No',
            ],
        ],
        'query_embedding_ttl_minutes' => 10080,
    ],
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
            // Infrastructure that supports an answer without being one. A client
            // must not pay more because their customers write in more languages.
            'ui_phrase' => 0,
            'search_translation' => 0,
        ],
        // Only these may go through LlmGateway::chatUnmetered().
        'unmetered_features' => ['ui_phrase', 'search_translation'],
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
        'unmetered_per_minute' => 30,
        'free_concurrency' => 2,
        'paid_concurrency' => 10,
    ],
];
