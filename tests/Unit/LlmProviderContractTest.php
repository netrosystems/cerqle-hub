<?php

namespace Tests\Unit;

use App\Modules\AI\Services\Llm\AnthropicProvider;
use App\Modules\AI\Services\Llm\GeminiProvider;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\OpenAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmProviderContractTest extends TestCase
{
    public function test_deepseek_uses_its_chat_api_and_reports_usage(): void
    {
        Http::fake(['api.deepseek.com/chat/completions' => function (Request $request) {
            $this->assertSame('deepseek-v4-flash', $request['model']);
            $this->assertSame('Bearer deepseek-key', $request->header('Authorization')[0]);

            return Http::response([
                'choices' => [['message' => ['content' => 'RAG answer']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
                'model' => 'deepseek-v4-flash',
            ]);
        }]);

        $provider = LlmManager::build('deepseek', ['api_key' => 'deepseek-key']);
        $response = $provider->chat([
            ['role' => 'user', 'content' => 'Answer from this context.'],
        ]);

        $this->assertSame('RAG answer', $response->content);
        $this->assertSame(12, $response->promptTokens);
        $this->assertSame(4, $response->completionTokens);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DeepSeek does not provide embeddings');
        $provider->embed(['context']);
    }

    public function test_gemini_uses_current_models_and_api_key_header(): void
    {
        Http::fake([
            '*:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
                'usageMetadata' => [],
            ]),
            '*:batchEmbedContents' => Http::response(['embeddings' => [['values' => [0.1, 0.2]]]]),
        ]);

        $provider = new GeminiProvider('gemini-key');
        $response = $provider->chat([['role' => 'user', 'content' => 'hello']]);
        $vectors = $provider->embed(['hello']);

        $this->assertSame('gemini-3.5-flash', $response->model);
        $this->assertSame([[0.1, 0.2]], $vectors);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'gemini-3.5-flash:generateContent')
            && ! str_contains($request->url(), '?key=')
            && $request->header('x-goog-api-key')[0] === 'gemini-key');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'gemini-embedding-2:batchEmbedContents'));
    }

    public function test_anthropic_default_is_not_a_retired_claude_3_model(): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => function (Request $request) {
            $this->assertSame('claude-haiku-4-5-20251001', $request['model']);

            return Http::response([
                'content' => [['text' => 'ok']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                'model' => $request['model'],
            ]);
        }]);

        $response = (new AnthropicProvider('anthropic-key'))->chat([
            ['role' => 'user', 'content' => 'hello'],
        ]);

        $this->assertSame('claude-haiku-4-5-20251001', $response->model);
    }

    public function test_openai_embedding_preserves_organization_header(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2]]],
            ]),
        ]);

        $vectors = (new OpenAiProvider('openai-key', 'gpt-4o-mini', 'text-embedding-3-small', 'org-123'))
            ->embed(['hello']);

        $this->assertSame([[0.1, 0.2]], $vectors);
        Http::assertSent(fn (Request $request) => $request->header('OpenAI-Organization')[0] === 'org-123');
    }

    public function test_managed_gpt5_models_use_the_supported_completion_limit(): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => function (Request $request) {
            $this->assertSame('gpt-5-nano', $request['model']);
            $this->assertSame(180, $request['max_completion_tokens']);
            $this->assertArrayNotHasKey('max_tokens', $request->data());
            $this->assertArrayNotHasKey('temperature', $request->data());

            return Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1],
                'model' => 'gpt-5-nano',
            ]);
        }]);

        (new OpenAiProvider('sk-test', 'gpt-5-nano'))
            ->chat([['role' => 'user', 'content' => 'Hi']], ['max_tokens' => 180, 'temperature' => 0.2]);
    }
}
