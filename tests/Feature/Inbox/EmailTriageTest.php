<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Services\EmailTriage;
use App\Modules\Shared\Models\Message;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class EmailTriageTest extends TestCase
{
    private EmailTriage $triage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->triage = new EmailTriage;
    }

    /** An unsaved message is enough: triage reads the payload, not the database. */
    private function mail(array $payload = [], string $body = 'Hi, my order has not arrived. Can you help?'): Message
    {
        return new Message(['channel' => 'email', 'direction' => 'in', 'body' => $body, 'payload' => $payload]);
    }

    public function test_a_person_asking_a_question_is_answered(): void
    {
        $result = $this->triage->classify($this->mail(['from_address' => 'olivia@example.com']));

        $this->assertSame(EmailTriage::INQUIRY, $result['category']);
        $this->assertTrue($result['reply']);
        $this->assertTrue($result['confident']);
    }

    public function test_declared_automated_and_bulk_mail_is_left_alone(): void
    {
        $cases = [
            'auto-submitted' => ['mail_headers' => ['auto-submitted' => 'auto-generated'], 'expect' => EmailTriage::AUTOMATED],
            'suppress-all' => ['mail_headers' => ['x-auto-response-suppress' => 'All'], 'expect' => EmailTriage::AUTOMATED],
            'list-id' => ['mail_headers' => ['list-id' => '<news.example.com>'], 'expect' => EmailTriage::BULK],
            'list-unsubscribe' => ['mail_headers' => ['list-unsubscribe' => '<https://x.test/u>'], 'expect' => EmailTriage::BULK],
            'precedence' => ['mail_headers' => ['precedence' => 'bulk'], 'expect' => EmailTriage::BULK],
        ];

        foreach ($cases as $name => $case) {
            $result = $this->triage->classify($this->mail([
                'from_address' => 'hello@example.com',
                'mail_headers' => $case['mail_headers'],
            ]));

            $this->assertSame($case['expect'], $result['category'], "[{$name}] classified wrongly");
            $this->assertFalse($result['reply'], "[{$name}] should not be answered");
            $this->assertNotSame('', $result['summary']);
        }
    }

    public function test_no_reply_style_senders_are_not_answered(): void
    {
        foreach (['no-reply@shop.test', 'noreply@shop.test', 'do-not-reply@shop.test', 'DoNotReply@shop.test', 'mailer-daemon@shop.test', 'postmaster@shop.test', 'bounces+123@shop.test'] as $from) {
            $result = $this->triage->classify($this->mail(['from_address' => $from]));

            $this->assertFalse($result['reply'], "Replied to {$from}");
        }
    }

    public function test_a_bounce_report_never_gets_a_reply(): void
    {
        $result = $this->triage->classify($this->mail([
            'from_address' => 'someone@example.com',
            'mail_headers' => ['content-type' => 'multipart/report; report-type=delivery-status'],
        ]));

        $this->assertSame(EmailTriage::LOOP, $result['category']);
        $this->assertFalse($result['reply']);
    }

    public function test_mail_from_the_mailbox_itself_is_a_loop(): void
    {
        $message = $this->mail(['from_address' => 'support@cerqle.test']);
        // The conversation relation supplies the mailbox address; stub it
        // rather than building the whole channel-account graph.
        $message->setRelation('conversation', new class extends Model
        {
            public function getAttribute($key)
            {
                return $key === 'channelAccount'
                    ? new class extends Model
                    {
                        protected $attributes = ['meta_json' => '{"email":"support@cerqle.test"}'];

                        protected $casts = ['meta_json' => 'array'];
                    }
                : null;
            }
        });

        $this->assertSame(EmailTriage::LOOP, $this->triage->classify($message)['category']);
    }

    public function test_a_campaign_without_list_headers_is_still_recognised(): void
    {
        // The case header checks miss: well-formed marketing that sets none of
        // the declarations. Counting links and spotting the unsubscribe works
        // without reading the words, so it holds in any language.
        $html = '<div>'.str_repeat('<a href="https://shop.test/p">Buy</a>', 8)
            .'<img src="https://t.test/o.gif" width="1" height="1">'
            .'<a href="https://shop.test/unsubscribe">Unsubscribe</a></div>';

        $result = $this->triage->classify($this->mail(
            ['from_address' => 'news@shop.test', 'html_body' => $html],
            'Shop now',
        ));

        $this->assertSame(EmailTriage::BULK, $result['category']);
        $this->assertFalse($result['reply']);
        $this->assertContains('unsubscribe_link', $result['signals']);
    }

    public function test_a_single_weak_signal_still_gets_answered_but_is_flagged(): void
    {
        // The decision taken with the product owner: a missed customer is
        // worse than an odd reply, so doubt does not mean silence.
        $result = $this->triage->classify($this->mail(['from_address' => 'billing@partner.test']));

        $this->assertSame(EmailTriage::UNCERTAIN, $result['category']);
        $this->assertTrue($result['reply'], 'Uncertain mail must still be answered.');
        $this->assertFalse($result['confident']);
        $this->assertContains('impersonal_sender', $result['signals']);
    }

    public function test_a_long_question_full_of_links_is_not_mistaken_for_a_campaign(): void
    {
        // A customer pasting several links is still a customer.
        $html = '<p>'.str_repeat('I have tried everything described in your guides and it still fails. ', 40)
            .str_repeat('<a href="https://example.com/x">this page</a> ', 6).'</p>';

        $result = $this->triage->classify($this->mail(
            ['from_address' => 'olivia@example.com', 'html_body' => $html],
            str_repeat('I have tried everything described in your guides and it still fails. ', 40),
        ));

        $this->assertTrue($result['reply']);
        $this->assertNotSame(EmailTriage::BULK, $result['category']);
    }

    public function test_every_verdict_carries_a_sentence_an_operator_can_read(): void
    {
        foreach ([[], ['mail_headers' => ['list-id' => '<a.b>']], ['from_address' => 'no-reply@x.test'], ['from_address' => 'updates@x.test']] as $payload) {
            $result = $this->triage->classify($this->mail($payload));

            $this->assertNotSame('', trim($result['summary']));
            $this->assertIsArray($result['signals']);
        }
    }
}
