<?php

namespace Tests\Feature\AI;

use App\Models\Workspace;
use App\Modules\AI\Services\Smart\ChoiceRenderer;
use App\Modules\AI\Services\Smart\ChoiceResolver;
use App\Modules\AI\Services\Smart\Choices;
use App\Modules\AI\ValueObjects\ChatbotAnswer;
use App\Modules\Inbox\Services\HandoverIntent;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reaching a human, and seeing the options, in any language.
 *
 * Before this, a customer could only ask for a person in English, and on every
 * channel except the web widget the bot's options were dropped entirely — so a
 * question like "which country?" arrived with nothing to choose from.
 */
class SmartBotHandoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.smart_bot.multilingual_handover', true);
        config()->set('ai.smart_bot.channel_choice_fallback', true);
    }

    public function test_the_existing_english_phrases_still_reach_a_human(): void
    {
        [$workspace, $conversation] = $this->conversation();
        $inbound = $this->inbound($conversation, 'I need a human please');

        $this->assertTrue(app(HandoverIntent::class)->wants($inbound, (int) $workspace->id));
    }

    public function test_picking_the_handoff_choice_reaches_a_human_whatever_language_it_is_written_in(): void
    {
        [$workspace, $conversation] = $this->conversation();
        // A label in a language this test never names, carrying its role.
        $this->botOffered($conversation, [
            Choices::make('Zzz mehr erfahren', Choices::ROLE_SEND, 1),
            Choices::make('Zzz persona kontakt', Choices::ROLE_HANDOFF, 2),
        ]);
        $inbound = $this->inbound($conversation, 'Zzz persona kontakt');

        $this->assertTrue(app(HandoverIntent::class)->wants($inbound, (int) $workspace->id));
    }

    public function test_answering_with_a_number_reaches_a_human_on_channels_without_buttons(): void
    {
        [$workspace, $conversation] = $this->conversation('whatsapp');
        $this->botOffered($conversation, [
            Choices::make('Zzz mehr erfahren', Choices::ROLE_SEND, 1),
            Choices::make('Zzz persona kontakt', Choices::ROLE_HANDOFF, 2),
        ]);
        $inbound = $this->inbound($conversation, '2', 'whatsapp');

        $this->assertTrue(app(HandoverIntent::class)->wants($inbound, (int) $workspace->id));
    }

    public function test_a_number_in_another_digit_system_selects_the_same_choice(): void
    {
        [, $conversation] = $this->conversation('whatsapp');
        $this->botOffered($conversation, [
            Choices::make('First', Choices::ROLE_SEND, 1),
            Choices::make('Second', Choices::ROLE_SEND, 2),
        ]);
        $inbound = $this->inbound($conversation, '٢', 'whatsapp');

        $this->assertSame('Second', app(ChoiceResolver::class)->resolve($inbound)['label']);
    }

    public function test_an_ordinary_message_is_not_mistaken_for_a_choice(): void
    {
        [, $conversation] = $this->conversation();
        $this->botOffered($conversation, [Choices::make('Yes', Choices::ROLE_SEND, 1), Choices::make('No', Choices::ROLE_SEND, 2)]);
        $inbound = $this->inbound($conversation, 'I have 2 accounts and need help with both');

        $this->assertNull(app(ChoiceResolver::class)->resolve($inbound));
    }

    public function test_a_stale_offer_is_no_longer_selectable(): void
    {
        [, $conversation] = $this->conversation();
        $this->botOffered($conversation, [Choices::make('Yes', Choices::ROLE_SEND, 1), Choices::make('No', Choices::ROLE_SEND, 2)], now()->subHours(3));
        $inbound = $this->inbound($conversation, '1');

        $this->assertNull(app(ChoiceResolver::class)->resolve($inbound));
    }

    public function test_messaging_channels_receive_the_options_as_a_numbered_list(): void
    {
        $answer = new ChatbotAnswer(
            'Which country are you travelling to?',
            quickReplies: [Choices::make('Thailand', Choices::ROLE_SEND, 1), Choices::make('Japan', Choices::ROLE_SEND, 2)],
        );

        $body = app(ChoiceRenderer::class)->body($answer, 'whatsapp', 'unused');

        $this->assertStringContainsString('1. Thailand', $body);
        $this->assertStringContainsString('2. Japan', $body);
    }

    public function test_the_web_widget_keeps_its_buttons_and_gets_no_numbered_list(): void
    {
        $answer = new ChatbotAnswer(
            'Which country are you travelling to?',
            quickReplies: [Choices::make('Thailand', Choices::ROLE_SEND, 1), Choices::make('Japan', Choices::ROLE_SEND, 2)],
        );

        $body = app(ChoiceRenderer::class)->body($answer, 'webchat', 'unused');

        $this->assertStringNotContainsString('1. Thailand', $body);
    }

    public function test_a_mocked_runner_with_no_answer_leaves_the_body_untouched(): void
    {
        // The reply job passes null when the runner is a test double; the
        // renderer must not assume an answer object exists.
        $this->assertSame('plain body', app(ChoiceRenderer::class)->body(null, 'whatsapp', 'plain body'));
    }

    public function test_legacy_string_choices_still_work_and_keep_their_handoff_behaviour(): void
    {
        $choices = Choices::normalise(['Tell me more', 'Talk to a person'], true);

        $this->assertSame(['send', 'handoff'], array_column($choices, 'role'));
        $this->assertSame(['qr_1', 'qr_2'], array_column($choices, 'id'));
    }

    public function test_a_model_can_never_mint_a_choice_that_summons_a_person(): void
    {
        $choices = Choices::fromModel(['Talk to a person', 'Something else']);

        $this->assertSame(['send', 'send'], array_column($choices, 'role'));
    }

    /** @return array{0: Workspace, 1: Conversation} */
    private function conversation(string $channel = 'webchat'): array
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        return [$workspace, $conversation];
    }

    /** @param list<array{id:string,label:string,role:string}> $choices */
    private function botOffered(Conversation $conversation, array $choices, $sentAt = null): void
    {
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'How can I help?',
            'status' => 'sent',
            'sent_by' => 'bot',
            'sent_at' => $sentAt ?? now(),
            'payload' => ['ai_answer' => ['quick_replies' => $choices, 'handoff_offer' => true]],
        ]);
    }

    private function inbound(Conversation $conversation, string $body, string $channel = 'webchat'): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => $channel,
            'type' => 'text',
            'body' => $body,
            'status' => 'received',
            'sent_at' => now(),
        ]);
    }
}
