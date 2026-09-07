<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Plan;
use App\Modules\Whatsapp\Models\WhatsappWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappWidgetEmbedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function launcher_remains_clickable_and_opens_the_whatsapp_prompt(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['whatsapp_chatbots' => 5]]));

        $widget = WhatsappWidget::create([
            'workspace_id' => $workspace->id,
            'widget_key' => 'whatsapp-widget-click-test',
            'display_phone' => '+880 1915 038044',
            'prefilled_message' => 'Hello from the widget',
            'greeting_message' => 'How can we help?',
            'agent_name' => 'Cerqle Support',
            'button_color' => '#25D366',
            'position' => 'bottom_right',
        ]);

        $response = $this->get(route('whatsapp.widget.embed', $widget->widget_key));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        $script = $response->getContent();
        $this->assertStringContainsString('width:43.2px;height:43.2px', $script);
        $this->assertStringNotContainsString('width:56px;height:56px', $script);
        $this->assertStringContainsString('#_wacw_btn svg{width:24px;height:24px}', $script);

        $this->assertStringContainsString('#_wacw_root{position:fixed;', $script);
        $this->assertStringContainsString('pointer-events:none;isolation:isolate', $script);
        $this->assertStringContainsString('#_wacw_btn{position:relative;z-index:3;', $script);
        $this->assertStringContainsString('pointer-events:auto;touch-action:manipulation', $script);
        $this->assertStringContainsString('#_wacw_pulse{position:absolute;inset:0;z-index:0;', $script);
        $this->assertStringContainsString('btn.type = \'button\';', $script);
        $this->assertStringContainsString("btn.addEventListener('click'", $script);
        $this->assertStringContainsString('tooltip.classList.add(\'open\')', $script);
        $this->assertStringContainsString('https://wa.me/8801915038044?text=Hello%20from%20the%20widget', $script);
        $this->assertStringContainsString('id="_wacw_tip_cta"', $script);
    }
}
