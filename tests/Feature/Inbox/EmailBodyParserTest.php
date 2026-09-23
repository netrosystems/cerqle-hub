<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Services\EmailBodyParser;
use Tests\TestCase;

class EmailBodyParserTest extends TestCase
{
    private EmailBodyParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new EmailBodyParser;
    }

    public function test_stylesheet_contents_never_reach_the_stored_text(): void
    {
        // This is the reported bug. strip_tags() removes the <style> tag but
        // keeps the CSS inside it, so the message arrived as a wall of
        // declarations with the actual words buried at the end.
        $mail = '<html><head><style>body{color:red;font-size:12px}.btn{padding:10px}</style></head><body><p>Your order shipped.</p></body></html>';

        $text = $this->parser->toText($mail);

        $this->assertSame('Your order shipped.', $text);
        $this->assertStringNotContainsString('color:red', $text);
        $this->assertStringNotContainsString('font-size', $text);
    }

    public function test_script_contents_never_reach_the_stored_text(): void
    {
        $text = $this->parser->toText('<body><script>var a=1;alert("x")</script><p>Hello</p></body>');

        $this->assertSame('Hello', $text);
        $this->assertStringNotContainsString('alert', $text);
    }

    public function test_paragraph_structure_survives_as_line_breaks(): void
    {
        $text = $this->parser->toText('<p>Hi Olivia,</p><p>Order #4821 is on its way.</p><p>Thanks,<br>The team</p>');

        $this->assertSame("Hi Olivia,\n\nOrder #4821 is on its way.\n\nThanks,\nThe team", $text);
    }

    public function test_plain_text_is_left_alone(): void
    {
        // Running plain text through an HTML parser would eat "a < b".
        $text = $this->parser->toText("Quick question: is a < b here?\n\nThanks");

        $this->assertSame("Quick question: is a < b here?\n\nThanks", $text);
        $this->assertNull($this->parser->toSafeHtml('Just plain words.'));
    }

    public function test_entities_and_accents_are_decoded_not_mangled(): void
    {
        $text = $this->parser->toText('<p>Caf&eacute; &amp; Bar — na&iuml;ve</p>');

        $this->assertSame('Café & Bar — naïve', $text);
    }

    public function test_safe_html_keeps_formatting_but_drops_anything_executable(): void
    {
        $html = $this->parser->toSafeHtml(
            '<html><head><style>.btn{background:#0a66c2}</style></head><body>'
            .'<h1>Shipped</h1><b>bold</b>'
            .'<script>steal()</script>'
            .'<p onclick="steal()">text</p>'
            .'<a href="javascript:alert(1)">bad</a>'
            .'<a href="https://example.com">good</a>'
            .'<iframe src="https://evil.example"></iframe>'
            .'<form action="https://evil.example"><input name="card"></form>'
            .'</body></html>'
        );

        // Formatting the sender intended is kept, including their stylesheet,
        // which lives in <head> and would otherwise be lost with it.
        $this->assertStringContainsString('<h1>Shipped</h1>', $html);
        $this->assertStringContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('background:#0a66c2', $html);

        // Anything that could execute, frame or harvest is gone.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('steal()', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<input', $html);

        $this->assertStringContainsString('https://example.com', $html);
    }

    public function test_obfuscated_javascript_urls_are_still_removed(): void
    {
        // A prefix check on the raw attribute misses every one of these.
        foreach (['java&#115;cript:alert(1)', "java\tscript:alert(1)", ' JaVaScRiPt:alert(1)', "jav\nascript:alert(1)"] as $href) {
            $html = $this->parser->toSafeHtml('<body><a href="'.$href.'">click</a></body>');
            $this->assertStringNotContainsString('alert', (string) $html, "Not neutralised: {$href}");
        }
    }

    public function test_links_are_forced_to_open_outside_the_reader(): void
    {
        $html = $this->parser->toSafeHtml('<body><a href="https://example.com">go</a></body>');

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('noopener', $html);
    }

    public function test_malformed_mail_still_yields_its_words(): void
    {
        // Real mail is full of unclosed tags; losing the message would be
        // worse than any formatting problem.
        $text = $this->parser->toText('<div><p>Unclosed paragraph<div>Another<span>bit');

        $this->assertStringContainsString('Unclosed paragraph', $text);
        $this->assertStringContainsString('Another', $text);
        $this->assertStringContainsString('bit', $text);
    }

    public function test_empty_input_is_handled(): void
    {
        $this->assertSame('', $this->parser->toText(''));
        $this->assertNull($this->parser->toSafeHtml(''));
        $this->assertNull($this->parser->toSafeHtml('   '));
    }
}
