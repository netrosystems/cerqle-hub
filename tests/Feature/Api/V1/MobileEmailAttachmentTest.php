<?php

namespace Tests\Feature\Api\V1;

use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MobileEmailAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_email_compose_accepts_and_processes_file_attachment(): void
    {
        Storage::fake('public');
        $context = $this->createWorkspaceContext();
        $token = $context['user']->createToken('mobile', ['*'])->plainTextToken;

        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap_smtp',
            'display_name' => 'Support Mailbox',
            'status' => 'active',
            'meta_json' => ['email' => 'support@example.com'],
        ]);

        $mockDriver = Mockery::mock(ChannelDriverInterface::class);
        $mockDriver->shouldReceive('send')->once()->andReturn('prov-msg-101');
        $mockManager = Mockery::mock(ChannelManager::class);
        $mockManager->shouldReceive('driver')->with('email')->andReturn($mockDriver);
        $this->app->instance(ChannelManager::class, $mockManager);

        $file = UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf');

        $res = $this->withToken($token)->postJson('/api/v1/mobile/email/compose', [
            'account_id' => $account->id,
            'to' => 'client@example.com',
            'subject' => 'Your Contract',
            'body' => 'Here is the contract.',
            'attachment' => $file,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('message.has_attachments', true)
            ->assertJsonPath('message.status', 'sent');

        $this->assertDatabaseHas('messages', [
            'channel' => 'email',
            'status' => 'sent',
            'type' => 'document',
        ]);
    }

    public function test_mobile_email_reply_accepts_and_processes_file_attachment(): void
    {
        Storage::fake('public');
        $context = $this->createWorkspaceContext();
        $token = $context['user']->createToken('mobile', ['*'])->plainTextToken;

        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap_smtp',
            'display_name' => 'Support Mailbox',
            'status' => 'active',
            'meta_json' => ['email' => 'support@example.com'],
        ]);

        $contact = Contact::create([
            'workspace_id' => $context['workspace']->id,
            'first_name' => 'Customer',
            'email' => 'customer@example.com',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $context['workspace']->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $mockDriver = Mockery::mock(ChannelDriverInterface::class);
        $mockDriver->shouldReceive('send')->once()->andReturn('prov-msg-102');
        $mockManager = Mockery::mock(ChannelManager::class);
        $mockManager->shouldReceive('driver')->with('email')->andReturn($mockDriver);
        $this->app->instance(ChannelManager::class, $mockManager);

        $file = UploadedFile::fake()->image('screenshot.png', 200, 200);

        $res = $this->withToken($token)->postJson("/api/v1/mobile/email/threads/{$conversation->uuid}/reply", [
            'body' => 'Please see this screenshot.',
            'attachment' => $file,
        ]);

        $res->assertOk()
            ->assertJsonPath('message.has_attachments', true)
            ->assertJsonPath('message.type', 'image')
            ->assertJsonPath('message.status', 'sent');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'channel' => 'email',
            'status' => 'sent',
            'type' => 'image',
        ]);
    }
}
