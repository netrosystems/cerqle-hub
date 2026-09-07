<?php

namespace Tests\Unit;

use App\Modules\Inbox\Services\InstagramDriver;
use PHPUnit\Framework\TestCase;

class InstagramAttachmentTypeTest extends TestCase
{
    public function test_attachment_types_and_text_fallback(): void
    {
        foreach (['image' => 'image', 'animated_image' => 'image', 'video' => 'video', 'audio' => 'audio', 'file' => 'document', 'share' => 'text'] as $provider => $expected) {
            $this->assertSame($expected, InstagramDriver::attachmentType(['message' => ['attachments' => [['type' => $provider]]]]));
        }
        $this->assertSame('text', InstagramDriver::attachmentType(['message' => ['text' => 'Hello']]));
        $this->assertSame('text', InstagramDriver::attachmentType([]));
    }
}
