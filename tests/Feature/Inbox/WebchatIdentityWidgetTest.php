<?php

namespace Tests\Feature\Inbox;

use App\Models\Plan;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebchatIdentityWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_logged_in_visitor_identity_is_attached_and_public_config_matches_widget_features(): void
    {
        Storage::fake('public');
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        $plan = Plan::create([
            'name' => 'Pro',
            'slug' => 'pro-'.uniqid(),
            'price_cents' => 4900,
            'currency_code' => 'USD',
            'white_label_enabled' => true,
        ]);
        $this->attachPlanToClient($client, $plan);

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'footer_company_name' => 'Netro',
            'launcher_logo_path' => 'widget-launchers/custom.png',
            'launcher_logo_disk' => 'public',
            'identity_verification' => true,
            'identity_secret' => 'secret-for-test',
        ]);
        Storage::disk('public')->put('widget-launchers/custom.png', 'png');

        $hash = hash_hmac('sha256', 'customer-123', 'secret-for-test');

        $response = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'visitor_id' => 'logged-in-device-a',
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'avatar' => 'https://example.com/jane.jpg',
            'external_id' => 'customer-123',
            'user_hash' => $hash,
            'identity_kind' => 'logged_in',
        ]);

        $response->assertOk()
            ->assertJsonPath('config.footer_company_name', 'Netro')
            ->assertJsonPath('config.require_prechat', false);

        $this->assertStringContainsString('/storage/', $response->json('config.launcher_logo_url'));

        $this->withHeaders(['X-Widget-Token' => $response->json('token')])
            ->postJson(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => 'Hello',
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'avatar' => 'https://example.com/jane.jpg',
                'external_id' => 'customer-123',
                'user_hash' => $hash,
                'identity_kind' => 'logged_in',
            ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);
        $this->assertSame('jane@example.com', $contact->email);
        $this->assertSame('https://example.com/jane.jpg', $contact->avatar);
        $this->assertSame('customer-123', $contact->custom_fields['webchat_external_id']);
        $this->assertFalse($contact->opt_in_email);
    }

    public function test_logged_in_widget_session_still_accepts_visitor_image_uploads(): void
    {
        Storage::fake('public');
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'name' => 'Logged Customer',
            'email' => 'customer@example.com',
            'external_id' => 'customer-456',
        ])->assertOk();

        $image = UploadedFile::fake()->image('quote.png', 180, 180);

        $send = $this->withHeaders(['X-Widget-Token' => $session->json('token')])
            ->post(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => 'Please check this image',
                'attachment' => $image,
            ]);

        $send->assertOk()
            ->assertJsonPath('message.role', 'visitor')
            ->assertJsonPath('message.type', 'image');

        $message = Message::where('channel', 'webchat')->sole();
        $this->assertSame('image', $message->type);
        $this->assertSame('Please check this image', $message->payload['caption']);
        $this->assertNotEmpty($message->payload['preview_url']);
    }

    public function test_unverified_identity_is_ignored_when_identity_verification_is_enabled(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'identity_verification' => true,
            'identity_secret' => 'secret-for-test',
        ]);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'visitor_id' => 'unverified-device-a',
            'name' => 'Spoofed Customer',
            'email' => 'spoof@example.com',
            'external_id' => 'customer-789',
            'user_hash' => 'wrong-hash',
            'identity_kind' => 'logged_in',
        ])->assertOk();

        $this->withHeaders(['X-Widget-Token' => $session->json('token')])
            ->postJson(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => 'Hello',
                'name' => 'Spoofed Customer',
                'email' => 'spoof@example.com',
                'external_id' => 'customer-789',
                'user_hash' => 'wrong-hash',
                'identity_kind' => 'logged_in',
            ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Customer 01', $contact->first_name);
        $this->assertNull($contact->email);
        $this->assertArrayNotHasKey('webchat_external_id', $contact->custom_fields ?? []);
    }

    public function test_anonymous_visitors_from_different_devices_get_distinct_inbox_threads(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        foreach (['device-a', 'device-b'] as $visitorId) {
            $session = $this->postJson(route('widget.session'), [
                'key' => $widget->widget_key,
                'visitor_id' => $visitorId,
            ])->assertOk();

            $this->withHeaders(['X-Widget-Token' => $session->json('token')])
                ->postJson(route('widget.send'), [
                    'key' => $widget->widget_key,
                    'message' => 'Message from '.$visitorId,
                ])->assertOk();
        }

        $this->assertSame(2, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertSame(2, Conversation::where('workspace_id', $workspace->id)->count());
        $this->assertSame(2, Message::where('channel', 'webchat')->count());
        $this->assertEqualsCanonicalizing(
            ['device-a', 'device-b'],
            Contact::where('workspace_id', $workspace->id)
                ->get()
                ->map(fn (Contact $contact) => $contact->custom_fields['webchat_visitor_id'] ?? null)
                ->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['Customer 01', 'Customer 02'],
            Contact::where('workspace_id', $workspace->id)->pluck('first_name')->all(),
        );
    }

    public function test_repeat_visit_from_same_anonymous_device_restores_only_its_thread(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        $firstSession = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'visitor_id' => 'returning-device',
        ])->assertOk();

        $this->withHeaders(['X-Widget-Token' => $firstSession->json('token')])
            ->postJson(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => 'Remember this message',
            ])->assertOk();

        $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'visitor_id' => 'returning-device',
        ])->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'Remember this message');

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, Conversation::where('workspace_id', $workspace->id)->count());
    }
}
