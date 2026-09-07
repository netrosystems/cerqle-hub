<?php

namespace Tests\Unit;

use App\Modules\AI\Services\Llm\LlmResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LlmResponseTest extends TestCase
{
    public function test_stored_result_retains_usage_and_authoritative_ledger_id(): void
    {
        $response = LlmResponse::fromStoredResult([
            'content' => 'Saved answer', 'promptTokens' => 12, 'completionTokens' => 7,
            'model' => 'test-model', 'latencyMs' => 5, 'creditUsageId' => 999,
        ], 42);

        $this->assertSame('Saved answer', $response->content);
        $this->assertSame(12, $response->promptTokens);
        $this->assertSame(7, $response->completionTokens);
        $this->assertSame('test-model', $response->model);
        $this->assertSame(5, $response->latencyMs);
        $this->assertSame(42, $response->creditUsageId);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidResults(): iterable
    {
        yield 'missing fields' => [[]];
        yield 'invalid counter' => [[
            'content' => 'Saved answer', 'promptTokens' => '12', 'completionTokens' => 7,
            'model' => 'test-model', 'latencyMs' => 5,
        ]];
        yield 'invalid content' => [[
            'content' => null, 'promptTokens' => 12, 'completionTokens' => 7,
            'model' => 'test-model', 'latencyMs' => 5,
        ]];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidResults')]
    public function test_invalid_stored_result_fails_closed(array $payload): void
    {
        $this->expectException(\UnexpectedValueException::class);
        LlmResponse::fromStoredResult($payload, 42);
    }
}
