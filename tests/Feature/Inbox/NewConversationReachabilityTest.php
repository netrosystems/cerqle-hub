<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewConversationReachabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_search_returns_channel_thread_indicators(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'messenger',
            'display_name' => 'FB Page',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Alice',
            'phone_e164' => '+14155552671',
            'email' => 'alice@example.com',
        ]);

        Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => 'psid-123',
            'last_message_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('client.inbox.contacts.search', ['q' => 'Alice']));
        $response->assertOk();

        $data = $response->json();
        $this->assertNotEmpty($data);
        $this->assertTrue($data[0]['has_messenger_thread']);
        $this->assertFalse($data[0]['has_instagram_thread']);
    }

    public function test_start_conversation_validates_whatsapp_phone_requirement(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $waAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'WhatsApp Biz',
            'status' => 'active',
            'phone_number_id' => '123456789',
        ]);

        // Contact without phone
        $contactWithoutPhone = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Web',
            'last_name' => 'Visitor',
            'source' => 'webchat',
            'custom_fields' => ['webchat_visitor_id' => 'vis-123'],
        ]);

        $res = $this->actingAs($user)->postJson(route('client.inbox.start'), [
            'contact_id' => $contactWithoutPhone->id,
            'channel_account_id' => $waAccount->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['channel_account_id']);

        // Update contact with phone
        $contactWithoutPhone->update(['phone_e164' => '+14155550000']);

        $res2 = $this->actingAs($user)->post(route('client.inbox.start'), [
            'contact_id' => $contactWithoutPhone->id,
            'channel_account_id' => $waAccount->id,
        ]);

        $res2->assertRedirect();
        $this->assertDatabaseHas('conversations', [
            'workspace_id' => $workspace->id,
            'contact_id' => $contactWithoutPhone->id,
            'channel_account_id' => $waAccount->id,
        ]);
    }

    public function test_start_conversation_validates_email_requirement(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $emailAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'email',
            'display_name' => 'Support Mailbox',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'NoEmail',
            'phone_e164' => '+14155551111',
        ]);

        $res = $this->actingAs($user)->postJson(route('client.inbox.start'), [
            'contact_id' => $contact->id,
            'channel_account_id' => $emailAccount->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['channel_account_id']);
    }

    public function test_start_conversation_validates_social_prior_thread_requirement(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $igAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'display_name' => 'Insta Support',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Random',
            'phone_e164' => '+14155552222',
        ]);

        $res = $this->actingAs($user)->postJson(route('client.inbox.start'), [
            'contact_id' => $contact->id,
            'channel_account_id' => $igAccount->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['channel_account_id']);
    }

    public function test_contact_update_api_saves_phone_and_returns_json(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Customer',
            'source' => 'webchat',
        ]);

        $res = $this->actingAs($user)->putJson(route('client.contacts.update', $contact->uuid), [
            'phone_e164' => '+14155553333',
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('contact.phone_e164', '+14155553333');

        $this->assertEquals('+14155553333', $contact->fresh()->phone_e164);
    }

    public function test_contact_search_detects_whatsapp_inbound_source_and_can_reach_flags(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // Contact 1: Imported contact with phone only (no conversations yet)
        $imported = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Imported',
            'phone_e164' => '+96170106293',
            'source' => 'import',
        ]);

        // Contact 2: WhatsApp inbound contact (has WhatsApp interaction)
        $waContact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'WA Inbound',
            'phone_e164' => '+96171568399',
            'source' => 'whatsapp_inbound',
        ]);

        $res = $this->actingAs($user)->getJson(route('client.inbox.contacts.search'));
        $res->assertOk();

        $data = collect($res->json());
        $importedData = $data->firstWhere('id', $imported->id);
        $waData = $data->firstWhere('id', $waContact->id);

        // Imported contact can be messaged via WhatsApp/SMS because phone exists, but has no prior threads
        $this->assertTrue($importedData['can_whatsapp']);
        $this->assertTrue($importedData['can_sms']);
        $this->assertFalse($importedData['can_email']);
        $this->assertFalse($importedData['has_whatsapp_thread']);
        $this->assertFalse($importedData['has_messenger_thread']);

        // WA Inbound contact has whatsapp thread recognized and can be messaged
        $this->assertTrue($waData['can_whatsapp']);
        $this->assertTrue($waData['has_whatsapp_thread']);
    }
}
