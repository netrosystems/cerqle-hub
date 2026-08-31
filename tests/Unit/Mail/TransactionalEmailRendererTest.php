<?php

namespace Tests\Unit\Mail;

use App\Services\Mail\TransactionalEmailRenderer;
use Tests\TestCase;

class TransactionalEmailRendererTest extends TestCase
{
    public function test_it_renders_an_email_safe_branded_layout_without_remote_assets(): void
    {
        config()->set('app.name', 'Cerqle');
        config()->set('app.url', 'https://cerqle.ai');

        $html = app(TransactionalEmailRenderer::class)->render(
            'Verify your account',
            '<p>Welcome to Cerqle.</p><p><a href="https://cerqle.ai/verify/123">Verify email</a></p>',
        );

        $this->assertStringContainsString('role="presentation"', $html);
        $this->assertStringContainsString('Verify your account', $html);
        $this->assertStringContainsString('background:#7c3f91', $html);
        $this->assertStringContainsString('https://cerqle.ai/verify/123', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('@import', $html);
        $this->assertStringNotContainsString('unsubscribe', strtolower($html));
    }

    public function test_plain_text_fallback_keeps_link_destination_and_readable_spacing(): void
    {
        $text = app(TransactionalEmailRenderer::class)->toPlainText(
            '<p>Hello <strong>Sam</strong>,</p><p><a href="https://cerqle.ai/login">Log in</a></p>',
        );

        $this->assertSame("Hello Sam,\nLog in (https://cerqle.ai/login)\n", $text);
    }

    public function test_it_preserves_a_complete_admin_authored_email_document(): void
    {
        $custom = '<!doctype html><html><body><p>Custom layout</p></body></html>';

        $this->assertSame(
            $custom,
            app(TransactionalEmailRenderer::class)->render('Custom', $custom),
        );
    }
}
