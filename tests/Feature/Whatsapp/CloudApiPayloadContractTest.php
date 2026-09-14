<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudApiPayloadContractTest extends TestCase
{
    public function test_display_name_methods_preserve_provider_results(): void
    {
        Http::fakeSequence()
            ->push(['success' => true], 200)
            ->push(['error' => ['message' => 'Rejected', 'code' => 100]], 400)
            ->push('', 502);
        $client = new CloudApiClient('300', 'test-token', 1);
        $this->assertSame([
            'success' => true, 'status' => 200, 'response' => ['success' => true],
        ], $client->requestDisplayNameChange('300', 'Demo'));
        $this->assertSame([
            'success' => false, 'status' => 400,
            'response' => ['error' => ['message' => 'Rejected', 'code' => 100]],
        ], CloudApiClient::requestDisplayNameChangeDirect('300', 'Demo', 'test-token'));
        $this->assertSame([
            'success' => false, 'status' => 502, 'response' => [],
        ], CloudApiClient::requestDisplayNameChangeDirect('300', 'Demo', 'test-token'));
    }

    public function test_template_edit_only_forwards_editable_fields(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $client = new CloudApiClient('300', 'test-token', 1);
        $components = [['type' => 'BODY', 'text' => 'Hello']];
        $client->editTemplate('400', [
            'category' => 'UTILITY', 'components' => $components,
            'name' => 'immutable_name', 'language' => 'en_US',
        ]);
        $client->editTemplate('400', ['category' => 'MARKETING']);
        Http::assertSent(fn ($request) => $request->data() === [
            'category' => 'UTILITY', 'components' => $components,
        ]);
        Http::assertSent(fn ($request) => $request->data() === ['category' => 'MARKETING']);
        Http::assertSentCount(2);
    }
}
