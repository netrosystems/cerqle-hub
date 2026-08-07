<?php

namespace Tests\Feature\Whatsapp;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The WhatsApp inbox is the entry point for the majority of contacts on a
 * fresh workspace. Because contact opt-in is a legal signal, the driver
 * must opt them in only when an explicit consent keyword is sent and must
 * opt them out only when an explicit opt-out keyword is sent. Anything
 * else must leave the existing opt_in_whatsapp value alone.
 *
 * CRITICAL INVARIANT under test here: SMS and WhatsApp are independent
 * consent channels. Consent (or revocation) given on WhatsApp MUST only
 * touch opt_in_whatsapp, never opt_in_sms. Every test in this file
 * therefore asserts the column that should move AND the column that must
 * stay put, so that a future regression that re-introduces the
 * cross-contamination bug will fail loudly.
 */
class WhatsappConsentKeywordTest extends TestCase
{
    use RefreshDatabase;

    private string $verifyToken = 'test-verify-token';

    private string $phoneNumberId = 'PHONE_ID';

    private function makeWaba(): WhatsappBusinessAccount
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'webhook_verify_token' => $this->verifyToken,
            'status' => 'active',
        ]);

        ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Test WA',
            'phone_number_id' => $this->phoneNumberId,
            'business_account_id' => $waba->waba_id,
            'status' => 'active',
        ]);

        return $waba;
    }

    private function postInbound(WhatsappBusinessAccount $waba, string $body, string $phone = '8801900000001', string $type = 'text', array $extra = []): void
    {
        $message = [
            'from' => $phone,
            'id' => 'wamid.'.uniqid('msg_', true),
            'timestamp' => now()->timestamp,
            'type' => $type,
        ];

        if ($type === 'text') {
            $message['text'] = ['body' => $body];
        } else {
            $message = array_merge($message, $extra);
        }

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $waba->waba_id,
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '+1555000000', 'phone_number_id' => $this->phoneNumberId],
                        'contacts' => [['profile' => ['name' => 'Alice'], 'wa_id' => $phone]],
                        'messages' => [$message],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $this->postJson("/webhooks/whatsapp/{$this->verifyToken}", $payload)
            ->assertStatus(200);
    }

    #[Test]
    public function optin_keyword_in_plain_text_opts_the_contact_in_on_whatsapp_only(): void
    {
        $waba = $this->makeWaba();
        $this->postInbound($waba, 'START');

        $contact = Contact::where('phone_e164', '+8801900000001')->firstOrFail();
        $this->assertTrue((bool) $contact->opt_in_whatsapp, 'WhatsApp opt-in should be granted');
        $this->assertFalse((bool) $contact->opt_in_sms, 'SMS consent must NEVER come from a WhatsApp message');
    }

    #[Test]
    public function optin_keyword_is_case_insensitive_and_tolerates_followup_text(): void
    {
        $waba = $this->makeWaba();
        $this->postInbound($waba, 'yes please sign me up');

        $contact = Contact::where('phone_e164', '+8801900000001')->firstOrFail();
        $this->assertTrue((bool) $contact->opt_in_whatsapp);
        $this->assertFalse((bool) $contact->opt_in_sms);
    }

    #[Test]
    public function optout_keyword_opts_the_contact_out_of_whatsapp_only(): void
    {
        $waba = $this->makeWaba();

        // Pre-seed: opted in on BOTH channels. We will then send STOP on
        // WhatsApp — opt_in_whatsapp must drop, opt_in_sms must NOT.
        Contact::create([
            'workspace_id' => $waba->workspace_id,
            'phone_e164' => '+8801900000001',
            'opt_in_sms' => true,
            'opt_in_whatsapp' => true,
            'source' => 'import',
        ]);

        $this->postInbound($waba, 'STOP');

        $contact = Contact::where('phone_e164', '+8801900000001')->firstOrFail();
        $this->assertFalse((bool) $contact->opt_in_whatsapp, 'WhatsApp opt-out should revoke WhatsApp consent');
        $this->assertTrue((bool) $contact->opt_in_sms, 'SMS consent must survive a WhatsApp STOP — independent channel');
    }

    #[Test]
    public function arabic_optin_keyword_opts_the_contact_in_on_whatsapp_only(): void
    {
        $waba = $this->makeWaba();
        $this->postInbound($waba, 'اشتراك');

        $contact = Contact::where('phone_e164', '+8801900000001')->firstOrFail();
        $this->assertTrue((bool) $contact->opt_in_whatsapp);
        $this->assertFalse((bool) $contact->opt_in_sms);
    }

    #[Test]
    public function arabic_optout_keyword_opts_the_contact_out_of_whatsapp_only(): void
    {
        $waba = $this->makeWaba();
        Contact::create([
            'workspace_id' => $waba->workspace_id,
            'phone_e164' => '+8801900000001',
            'opt_in_sms' => true,
            'opt_in_whatsapp' => true,
            'source' => 'import',
        ]);

        $this->postInbound($waba, 'إيقاف');

        $contact = Contact::where('phone_e164', '+8801900000001')->firstOrFail();
        $this->assertFalse((bool) $contact->opt_in_whatsapp);
        $this->assertTrue((bool) $contact->opt_in_sms);
    }

    #[Test]
    public function no_keyword_leaves_opt_in_whatsapp_at_its_existing_value(): void
    {
        $waba = $this->makeWaba();

        // Pre-seed: explicitly opted-OUT on WhatsApp, opted-IN on SMS. A
        // normal conversational message must touch neither.
        Contact::create([
            'workspace_id' => $waba->workspace_id,
            'phone_e164' => '+8801900000001',
            'opt_in_sms' => true,
            'opt_in_whatsapp' => false,
            'source' => 'manual',
        ]);

        $this->postInbound($waba, 'Just a normal question about my order');

        $contact = Contact::where('phone_e164', '+8801900000001')->firstOrFail();
        $this->assertFalse((bool) $contact->opt_in_whatsapp);
        $this->assertTrue((bool) $contact->opt_in_sms);
    }

    #[Test]
    public function optin_keyword_in_button_reply_title_counts_as_consent_on_whatsapp_only(): void
    {
        $waba = $this->makeWaba();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $waba->waba_id,
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '+1555000000', 'phone_number_id' => $this->phoneNumberId],
                        'contacts' => [['profile' => ['name' => 'Alice'], 'wa_id' => '8801900000001']],
                        'messages' => [[
                            'from' => '8801900000001',
                            'id' => 'wamid.'.uniqid('msg_', true),
                            'timestamp' => now()->timestamp,
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'button',
                                'button_reply' => ['id' => 'optin', 'title' => 'Yes, subscribe me'],
                            ],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $this->postJson("/webhooks/whatsapp/{$this->verifyToken}", $payload)->assertStatus(200);

        $contact = Contact::where('phone_e164', '+8801900000001')->firstOrFail();
        $this->assertTrue((bool) $contact->opt_in_whatsapp);
        $this->assertFalse((bool) $contact->opt_in_sms);
    }

    #[Test]
    public function optout_keyword_in_list_reply_title_opts_contact_out_of_whatsapp_only(): void
    {
        $waba = $this->makeWaba();
        Contact::create([
            'workspace_id' => $waba->workspace_id,
            'phone_e164' => '+8801900000001',
            'opt_in_sms' => true,
            'opt_in_whatsapp' => true,
            'source' => 'import',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $waba->waba_id,
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '+1555000000', 'phone_number_id' => $this->phoneNumberId],
                        'contacts' => [['profile' => ['name' => 'Alice'], 'wa_id' => '8801900000001']],
                        'messages' => [[
                            'from' => '8801900000001',
                            'id' => 'wamid.'.uniqid('msg_', true),
                            'timestamp' => now()->timestamp,
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'list',
                                'list_reply' => ['id' => 'unsub', 'title' => 'STOP all messages', 'description' => ''],
                            ],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $this->postJson("/webhooks/whatsapp/{$this->verifyToken}", $payload)->assertStatus(200);

        $contact = Contact::where('phone_e164', '+8801900000001')->firstOrFail();
        $this->assertFalse((bool) $contact->opt_in_whatsapp);
        $this->assertTrue((bool) $contact->opt_in_sms);
    }

    #[Test]
    public function empty_body_on_existing_contact_leaves_opt_in_whatsapp_alone(): void
    {
        $waba = $this->makeWaba();

        // Pre-seed an explicitly opted-OUT contact who already exists on the
        // workspace. The follow-up image message must NOT silently re-opt
        // them in just because they happened to send something.
        Contact::create([
            'workspace_id' => $waba->workspace_id,
            'phone_e164' => '+8801900000001',
            'opt_in_sms' => true,
            'opt_in_whatsapp' => false,
            'source' => 'import',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $waba->waba_id,
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '+1555000000', 'phone_number_id' => $this->phoneNumberId],
                        'contacts' => [['profile' => ['name' => 'Alice'], 'wa_id' => '8801900000001']],
                        'messages' => [[
                            'from' => '8801900000001',
                            'id' => 'wamid.'.uniqid('msg_', true),
                            'timestamp' => now()->timestamp,
                            'type' => 'image',
                            'image' => ['link' => 'https://example.test/x.jpg', 'caption' => null],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $this->postJson("/webhooks/whatsapp/{$this->verifyToken}", $payload)->assertStatus(200);

        $contact = Contact::where('phone_e164', '+8801900000001')->firstOrFail();
        $this->assertFalse((bool) $contact->opt_in_whatsapp, 'A non-keyword follow-up from an opted-out contact must not re-opt them in');
        $this->assertTrue((bool) $contact->opt_in_sms, 'SMS consent must not be touched by a WhatsApp message');
    }
}