<?php

namespace Tests\Feature\Inbox;

use App\Models\Plan;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatWidgetCrudTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    public function test_can_delete_owned_chat_widget(): void
    {
        $workspace = $this->ctx['workspace'];
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channelAccount->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->delete(route('client.inbox.chat-widgets.destroy', $widget->id));

        $response->assertRedirect(route('client.inbox.chat-widgets.index'));
        $this->assertDatabaseMissing('chat_widgets', ['id' => $widget->id]);
        $this->assertDatabaseHas('channel_accounts', [
            'id' => $channelAccount->id,
            'status' => 'inactive',
        ]);
    }

    public function test_cannot_delete_another_workspaces_chat_widget(): void
    {
        $other = $this->createWorkspaceContext();
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $other['workspace']->id,
            'channel' => 'webchat',
            'display_name' => 'Other website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $other['workspace']->id,
            'channel_account_id' => $channelAccount->id,
            'name' => 'Other website chat',
            'position' => 'bottom_right',
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->delete(route('client.inbox.chat-widgets.destroy', $widget->id));

        $response->assertStatus(403);
        $this->assertDatabaseHas('chat_widgets', ['id' => $widget->id]);
        $this->assertDatabaseHas('channel_accounts', [
            'id' => $channelAccount->id,
            'status' => 'active',
        ]);
    }

    public function test_widget_exposes_client_footer_brand_with_a_safe_default(): void
    {
        $workspace = $this->ctx['workspace'];
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channelAccount->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        $this->assertSame('Cerqle', $widget->publicConfig()['footer_company_name']);
        $this->assertStringContainsString('cerqle-icon-white-bg.svg', $widget->publicConfig()['launcher_logo_url']);
        $this->assertGreaterThanOrEqual(1, $widget->publicConfig()['available_team']['count']);
        $this->assertArrayNotHasKey('email', $widget->publicConfig()['available_team']['members'][0]);
        $this->assertArrayNotHasKey('id', $widget->publicConfig()['available_team']['members'][0]);

        $widget->update(['footer_company_name' => 'Netro Systems']);

        $this->assertSame('Netro Systems', $widget->fresh()->publicConfig()['footer_company_name']);
    }

    public function test_custom_launcher_logo_requires_a_pro_entitlement_and_is_served_to_eligible_widgets(): void
    {
        Storage::fake('public');
        $workspace = $this->ctx['workspace'];
        $plan = Plan::create([
            'name' => 'Pro',
            'slug' => 'pro-'.uniqid(),
            'price_cents' => 4900,
            'currency_code' => 'USD',
            'white_label_enabled' => true,
        ]);
        $this->attachPlanToClient($this->ctx['client'], $plan);

        $this->actingAs($this->ctx['user'])
            ->post(route('client.inbox.chat-widgets.store'), [
                'name' => 'Branded chat',
                'position' => 'bottom_right',
                'launcher_logo' => UploadedFile::fake()->image('logo.png', 96, 96),
            ])
            ->assertRedirect(route('client.inbox.chat-widgets.index'));

        $widget = ChatWidget::where('workspace_id', $workspace->id)->sole();
        $this->assertNotNull($widget->launcher_logo_path);
        Storage::disk('public')->assertExists($widget->launcher_logo_path);
        $this->assertStringContainsString('/storage/', $widget->publicConfig()['launcher_logo_url']);
    }

    public function test_non_pro_workspace_cannot_upload_a_custom_launcher_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->ctx['user'])
            ->post(route('client.inbox.chat-widgets.store'), [
                'name' => 'Free plan chat',
                'position' => 'bottom_right',
                'launcher_logo' => UploadedFile::fake()->image('logo.png', 96, 96),
            ])
            ->assertSessionHasErrors([
                'launcher_logo' => 'Upgrade to Pro to upload a custom launcher icon.',
            ]);

        $this->assertDatabaseMissing('chat_widgets', [
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Free plan chat',
        ]);
    }
}
