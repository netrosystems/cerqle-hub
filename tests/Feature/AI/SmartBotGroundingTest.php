<?php

namespace Tests\Feature\AI;

use App\Models\Plan;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\AI\Services\Smart\GroundingValidator;
use App\Modules\AI\Services\Smart\ReplyContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A wrong price is the most damaging thing a support bot can say.
 *
 * The model reports whether it stayed grounded; these tests prove that claim is
 * checked rather than believed, that a rejected answer never reaches the
 * customer, and that they are not charged for it.
 */
class SmartBotGroundingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.smart_bot.structured_output', true);
        config()->set('ai.smart_bot.grounding_validation', true);
    }

    public function test_a_price_absent_from_the_evidence_is_rejected(): void
    {
        $verdict = $this->check('Our starter plan costs 4500 taka a month.', 'The starter plan costs 2500 taka a month.');

        $this->assertSame('rejected', $verdict['result']);
        $this->assertStringContainsString('4500', $verdict['reason']);
    }

    public function test_a_price_present_in_the_evidence_is_accepted(): void
    {
        $verdict = $this->check('Our starter plan costs 2500 taka a month.', 'The starter plan costs 2500 taka a month.');

        $this->assertSame('passed', $verdict['result']);
    }

    public function test_figures_written_in_other_digit_systems_still_match(): void
    {
        $validator = app(GroundingValidator::class);

        $this->assertSame([], $validator->unsupportedFigures('The fee is 1,000 taka.', 'Fee: ১০০০ taka'));
        $this->assertSame([], $validator->unsupportedFigures('It costs 1.20 dollars.', 'Price is 1.2 dollars'));
        $this->assertSame([], $validator->unsupportedFigures('The pack holds ৳128 credit.', 'Recharge 128tk for this pack.'));
    }

    public function test_a_size_is_not_supported_by_the_same_number_in_another_unit(): void
    {
        $this->assertSame(
            ['5 gb'],
            app(GroundingValidator::class)->unsupportedFigures('You get 5GB.', 'The offer lasts 5 days.'),
        );
    }

    public function test_an_unsupported_figure_inside_a_choice_is_rejected(): void
    {
        $verdict = $this->check(
            'Here are the options.',
            'The starter plan costs 2500 taka a month.',
            choices: ['Starter at 2500', 'Premium at 9900'],
        );

        $this->assertSame('rejected', $verdict['result']);
        $this->assertStringContainsString('9900', $verdict['reason']);
    }

    public function test_a_reply_that_only_asks_a_question_is_accepted_even_when_ungrounded(): void
    {
        // Rejecting these would turn every legitimate follow-up into a handoff.
        $verdict = $this->check('Which plan are you on at the moment?', 'Anything at all.', grounded: false);

        $this->assertSame('passed', $verdict['result']);
    }

    public function test_general_guidance_naming_a_plan_is_rejected_when_there_is_no_evidence(): void
    {
        $verdict = $this->check('Our premium plan includes priority support.', null);

        $this->assertSame('rejected', $verdict['result']);
    }

    public function test_step_numbers_are_not_treated_as_claims(): void
    {
        $this->assertSame(
            [],
            app(GroundingValidator::class)->unsupportedFigures('Step 2: open settings.', 'Open settings from the menu.'),
        );
    }

    public function test_a_json_reply_is_read_even_when_the_provider_wraps_it_in_prose(): void
    {
        $parsed = app(ReplyContract::class)->parse(
            'Sure! {"reply": "We open at 9am.", "quick_replies": ["Hours", "Location"], '
            .'"response_type": "answer", "grounded": true, "language": "en"} Hope that helps.'
        );

        $this->assertSame('We open at 9am.', $parsed['reply']);
        $this->assertSame('en', $parsed['language']);
    }

    public function test_a_brace_inside_the_reply_text_does_not_truncate_the_object(): void
    {
        $parsed = app(ReplyContract::class)->parse(
            '{"reply": "Use the {code} placeholder", "quick_replies": [], "response_type": "answer", "grounded": true, "language": "en"}'
        );

        $this->assertSame('Use the {code} placeholder', $parsed['reply']);
    }

    public function test_an_ungrounded_answer_is_never_sent_and_costs_nothing(): void
    {
        [$workspace, $bot] = $this->botWithEvidence('The starter plan costs 2500 taka a month.');
        $gateway = $this->recordingGateway();

        $calls = 0;
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]], 200),
            'api.openai.com/v1/chat/completions' => function () use (&$calls) {
                $calls++;

                // Both the first attempt and the retry invent the same price.
                return Http::response([
                    'choices' => [['message' => ['content' => json_encode([
                        'reply' => 'The starter plan costs 9900 taka a month.',
                        'quick_replies' => [],
                        'response_type' => 'answer',
                        'grounded' => true,
                        'language' => 'en',
                    ])]]],
                    'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 12],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'How much is the starter plan?', (int) $workspace->id);

        $this->assertStringNotContainsString('9900', (string) $result['reply'], 'The invented price must never reach the customer.');
        $this->assertSame('fallback', $result['answer_origin']);
        $this->assertSame(2, $calls, 'One retry inside the same reservation.');

        // One reservation for the whole attempt, and it is handed back: the
        // client pays for answers, not for attempts. (The refund mechanism
        // itself is covered by AiCreditServiceTest; what matters here is that
        // the runner asks for it, with a reason an operator can read.)
        $this->assertDatabaseCount('ai_credit_usages', 1);
        $this->assertSame(['ungrounded_answer'], $gateway->rejections);
    }

    public function test_a_grounded_answer_is_sent_and_kept(): void
    {
        [$workspace, $bot] = $this->botWithEvidence('The starter plan costs 2500 taka a month.');
        $gateway = $this->recordingGateway();

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]], 200),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'reply' => 'The starter plan is 2500 taka a month.',
                    'quick_replies' => ['See other plans', 'Talk to a person'],
                    'response_type' => 'answer',
                    'grounded' => true,
                    'language' => 'en',
                ])]]],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 12],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'How much is the starter plan?', (int) $workspace->id);

        $this->assertStringContainsString('2500', (string) $result['reply']);
        $this->assertSame(['See other plans', 'Talk to a person'], array_column($result['quick_replies'], 'label'));
        // Accepted, so nothing is handed back and the turn is billed normally.
        $this->assertDatabaseHas('ai_credit_usages', ['status' => 'succeeded']);
        $this->assertSame([], $gateway->rejections);
    }

    public function test_the_schema_is_requested_and_degrades_when_the_provider_refuses_it(): void
    {
        [$workspace, $bot] = $this->botWithEvidence('We deliver nationwide.');

        $modes = [];
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]], 200),
            'api.openai.com/v1/chat/completions' => function ($request) use (&$modes) {
                $body = json_decode($request->body(), true);
                $modes[] = $body['response_format']['type'] ?? 'none';
                if (($body['response_format']['type'] ?? null) === 'json_schema') {
                    return Http::response(['error' => ['message' => 'Invalid parameter: response_format']], 400);
                }

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode([
                        'reply' => 'We deliver nationwide.',
                        'quick_replies' => [],
                        'response_type' => 'answer',
                        'grounded' => true,
                        'language' => 'en',
                    ])]]],
                    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Do you deliver everywhere?', (int) $workspace->id);

        $this->assertSame(['json_schema', 'json_object'], $modes);
        $this->assertSame('We deliver nationwide.', $result['reply']);
        // One logical answer, one reservation, however many modes it took.
        $this->assertDatabaseCount('ai_credit_usages', 1);
    }

    public function test_a_long_reply_is_capped(): void
    {
        [$workspace, $bot] = $this->botWithEvidence('We deliver nationwide.');

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]], 200),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'reply' => str_repeat('word ', 140).'end.',
                    'quick_replies' => [],
                    'response_type' => 'answer',
                    'grounded' => true,
                    'language' => 'en',
                ])]]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 200],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        $result = app(ChatbotRunner::class)->runForApi($bot, 'Do you deliver everywhere?', (int) $workspace->id);

        $this->assertLessThanOrEqual(70, count(preg_split('/\s+/u', trim((string) $result['reply']))));
    }

    /**
     * A gateway that behaves exactly like the real one but remembers every
     * refund it was asked to make, so a test can assert on the reason code
     * without reaching into the credit ledger.
     */
    private function recordingGateway(): LlmGateway
    {
        $gateway = new class(app(AiCreditService::class)) extends LlmGateway
        {
            /** @var list<string> */
            public array $rejections = [];

            public function rejectMalformed(LlmResponse $response, string $code = 'malformed_response'): void
            {
                $this->rejections[] = $code;
                parent::rejectMalformed($response, $code);
            }
        };
        $this->app->instance(LlmGateway::class, $gateway);

        return $gateway;
    }

    /** @param list<string> $choices */
    private function check(string $reply, ?string $evidence, bool $grounded = true, array $choices = []): array
    {
        $results = [];
        if ($evidence !== null) {
            $chunk = new AiKbChunk(['content' => $evidence]);
            $results[] = ['chunk' => $chunk, 'score' => 0.9];
        }

        return app(GroundingValidator::class)->check([
            'reply' => $reply,
            'quick_replies' => $choices,
            'response_type' => 'answer',
            'grounded' => $grounded,
        ], $results, 'How much is the plan?');
    }

    /** @return array{0: Workspace, 1: AiChatbot} */
    private function botWithEvidence(string $evidence): array
    {
        // Credits are only actually charged when enforcement is on and the plan
        // grants an allowance; in shadow mode a refund would be a no-op and
        // these assertions would prove nothing.
        config()->set('ai.credits.enforced', true);
        $context = $this->createWorkspaceContext();
        $workspace = $context['workspace'];
        $plan = Plan::factory()->create([
            'monthly_price_cents' => 2000,
            'price_cents' => 2000,
            'limits' => ['ai_credits_per_month' => 50],
        ]);
        $this->attachPlanToClient($context['client'], $plan);
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Plans',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $document = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Plans',
            'source_type' => 'text',
            'source_ref' => $evidence,
            'status' => 'indexed',
        ]);
        AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $document->id,
            'ord' => 0,
            'content' => $evidence,
            'tokens' => 12,
            'embedding' => json_encode([0.1, 0.2, 0.3]),
        ]);
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Plans Bot',
            'ai_kb_id' => $kb->id,
            'enabled' => true,
        ]);

        return [$workspace, $bot->load('knowledgeBase')];
    }
}
