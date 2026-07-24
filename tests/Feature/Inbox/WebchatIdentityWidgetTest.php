<?php

namespace Tests\Feature\Inbox;

use App\Models\Plan;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
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
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'avatar' => 'https://example.com/jane.jpg',
            'external_id' => 'customer-123',
            'user_hash' => $hash,
        ]);

        $response->assertOk()
            ->assertJsonPath('config.footer_company_name', 'Netro')
            ->assertJsonPath('config.require_prechat', false);

        $this->assertStringContainsString('/storage/', $response->json('config.launcher_logo_url'));

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

        $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'name' => 'Spoofed Customer',
            'email' => 'spoof@example.com',
            'external_id' => 'customer-789',
            'user_hash' => 'wrong-hash',
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Website visitor', $contact->first_name);
        $this->assertNull($contact->email);
        $this->assertArrayNotHasKey('webchat_external_id', $contact->custom_fields ?? []);
    }
}
