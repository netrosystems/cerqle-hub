<?php

namespace Tests\Feature;

use App\Modules\AI\Models\AiProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderExclusivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabling_a_provider_disables_the_other_workspace_providers(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'openai-key'],
            'enabled' => true,
        ]);
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'gemini',
            'credentials' => ['api_key' => 'gemini-key'],
            'enabled' => false,
        ]);

        $this->actingAs($user)
            ->put(route('client.ai.providers.update', 'gemini'), [
                'enabled' => true,
                'default_model_chat' => 'gemini-3.5-flash',
            ])
            ->assertRedirect();

        $this->assertFalse(AiProviderConfig::where('workspace_id', $workspace->id)->where('provider', 'openai')->value('enabled'));
        $this->assertTrue(AiProviderConfig::where('workspace_id', $workspace->id)->where('provider', 'gemini')->value('enabled'));
        $this->assertSame(1, AiProviderConfig::where('workspace_id', $workspace->id)->where('enabled', true)->count());
    }
}
