<?php

namespace Tests\Unit;

use App\Modules\Inbox\Services\GenericMailboxClient;
use ReflectionMethod;
use Tests\TestCase;

class GenericMailboxMimeParserTest extends TestCase
{
    public function test_it_prefers_and_decodes_the_plain_part_of_a_multipart_email(): void
    {
        $client = app(GenericMailboxClient::class);
        $structure = (object) [
            'type' => 1,
            'subtype' => 'ALTERNATIVE',
            'parts' => [
                (object) [
                    'type' => 0,
                    'subtype' => 'PLAIN',
                    'encoding' => 4,
                    'parameters' => [(object) ['attribute' => 'charset', 'value' => 'UTF-8']],
                ],
                (object) [
                    'type' => 0,
                    'subtype' => 'HTML',
                    'encoding' => 3,
                    'parameters' => [(object) ['attribute' => 'charset', 'value' => 'UTF-8']],
                ],
            ],
        ];

        $part = $this->invoke($client, 'preferredTextPart', [$structure]);

        $this->assertSame('1', $part['section']);
        $this->assertFalse($part['html']);
        $this->assertSame(
            "Weekly report\nEverything is healthy.",
            $this->invoke($client, 'decodeBody', ['Weekly=20report=0AEverything=20is=20healthy.', 4, 'UTF-8', false]),
        );
    }

    public function test_it_ignores_attachments_and_converts_nested_html_to_readable_text(): void
    {
        $client = app(GenericMailboxClient::class);
        $structure = (object) [
            'type' => 1,
            'subtype' => 'MIXED',
            'parts' => [
                (object) [
                    'type' => 3,
                    'subtype' => 'PDF',
                    'encoding' => 3,
                    'disposition' => 'ATTACHMENT',
                    'dparameters' => [(object) ['attribute' => 'filename', 'value' => 'report.pdf']],
                ],
                (object) [
                    'type' => 1,
                    'subtype' => 'ALTERNATIVE',
                    'parts' => [
                        (object) [
                            'type' => 0,
                            'subtype' => 'HTML',
                            'encoding' => 3,
                            'parameters' => [(object) ['attribute' => 'charset', 'value' => 'UTF-8']],
                        ],
                    ],
                ],
            ],
        ];

        $part = $this->invoke($client, 'preferredTextPart', [$structure]);

        $this->assertSame('2.1', $part['section']);
        $this->assertTrue($part['html']);
        $this->assertSame(
            "Hello Cerqle\nReport ready",
            $this->invoke($client, 'decodeBody', [base64_encode('<p>Hello <strong>Cerqle</strong></p><div>Report ready</div>'), 3, 'UTF-8', true]),
        );
    }

    private function invoke(object $target, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
