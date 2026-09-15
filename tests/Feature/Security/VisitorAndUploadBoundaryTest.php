<?php

namespace Tests\Feature\Security;

use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\Media\AttachmentService;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisitorAndUploadBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_external_identity_cannot_restore_legacy_victim_history(): void
    {
        ['workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        $account = ChannelAccount::create(['workspace_id' => $workspace->id, 'channel' => 'webchat', 'display_name' => 'Test', 'status' => 'active']);
        $widget = ChatWidget::create(['workspace_id' => $workspace->id, 'channel_account_id' => $account->id, 'name' => 'Test', 'position' => 'bottom_right', 'identity_verification' => false]);
        $victim = $this->postJson(route('widget.session'), ['key' => $widget->widget_key, 'visitor_id' => 'victim-device'])->assertOk();
        $conversation = Conversation::findOrFail($victim->json('conversation_id'));
        $conversation->contact->update(['custom_fields' => array_merge($conversation->contact->custom_fields ?? [], ['webchat_external_id' => 'known-customer'])]);
        Message::create(['conversation_id' => $conversation->id, 'channel' => 'webchat', 'direction' => 'in', 'type' => 'text', 'body' => 'Private history']);
        $attacker = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key, 'visitor_id' => 'other-device', 'external_id' => 'known-customer', 'identity_kind' => 'logged_in',
        ])->assertOk()->assertJsonCount(0, 'messages');
        $this->assertNotSame($victim->json('conversation_id'), $attacker->json('conversation_id'));
    }

    public function test_storage_extension_comes_from_content_not_original_name(): void
    {
        Storage::fake('public');
        ['user' => $user] = $this->createWorkspaceContext();
        $file = UploadedFile::fake()->createWithContent('payload.html', 'This is ordinary plain text.');
        $media = app(MediaService::class)->store($file, $user);
        $this->assertStringEndsWith('.txt', $media->path);
        $attachment = app(AttachmentService::class)->processUpload($file);
        $this->assertStringEndsWith('.txt', $attachment['path']);
        $generated = UploadedFile::fake()->image('payload.jpg');
        $image = new UploadedFile($generated->getPathname(), 'payload.html', null, null, true);
        $stored = app(MediaService::class)->store($image, $user);
        $this->assertStringNotContainsString('.html', $stored->path);
        $this->assertStringStartsWith('image/', $stored->mime_type);
    }

    public function test_active_html_is_never_stored_with_browser_executable_extension(): void
    {
        Storage::fake('public');
        ['user' => $user] = $this->createWorkspaceContext();
        $file = UploadedFile::fake()->createWithContent('payload.txt', '<html><script>alert(1)</script></html>');
        $media = app(MediaService::class)->store($file, $user);
        $this->assertStringEndsWith('.bin', $media->path);
    }
}
