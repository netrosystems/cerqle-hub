<?php

namespace Tests\Unit;

use App\Modules\Inbox\Services\MetaMessageAttachmentNormalizer;
use PHPUnit\Framework\TestCase;

class MetaMessageAttachmentNormalizerTest extends TestCase
{
    public function test_it_preserves_caption_and_splits_multiple_meta_attachments(): void
    {
        $messages = (new MetaMessageAttachmentNormalizer)->messagesFromEvent([
            'message' => [
                'mid' => 'meta-message-1',
                'text' => 'Customer caption',
                'attachments' => [
                    ['type' => 'image', 'payload' => ['url' => 'https://scontent.example.fbcdn.net/photo.jpg']],
                    ['type' => 'audio', 'payload' => ['url' => 'https://lookaside.fbsbx.com/voice.m4a']],
                ],
            ],
        ], 'messenger');

        $this->assertCount(2, $messages);
        $this->assertSame('image', $messages[0]['type']);
        $this->assertSame('audio', $messages[1]['type']);
        $this->assertSame('Customer caption', $messages[0]['body']);
        $this->assertSame('Customer caption', $messages[1]['body']);
        $this->assertSame('meta-message-1', $messages[0]['provider_message_id']);
        $this->assertSame('meta-message-1:1', $messages[1]['provider_message_id']);
        $this->assertSame('meta', $messages[0]['payload']['_meta_attachment']['provider']);
    }

    public function test_media_without_caption_does_not_invent_visible_image_text(): void
    {
        $messages = (new MetaMessageAttachmentNormalizer)->messagesFromEvent([
            'message' => [
                'mid' => 'meta-message-2',
                'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://scontent.example.fbcdn.net/photo.jpg']]],
            ],
        ], 'instagram');

        $this->assertSame('', $messages[0]['body']);
    }
}
