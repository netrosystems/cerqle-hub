<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudApiSenderSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_sender_respects_business_app_disconnect(): void
    {
        $context = $this->createSubscribedWorkspaceContext();
        $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $context['workspace']->id]);
        $phone = WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id, 'phone_number_id' => '300',
            'display_phone' => '+15550000001', 'connection_mode' => 'coexistence',
        ]);
        $this->assertSame('300', CloudApiClient::forWorkspace($context['workspace']->id)?->phoneNumberId());
        $phone->update(['coexistence_meta' => ['disconnected_at' => now()->toIso8601String()]]);
        $this->assertNull(CloudApiClient::forWorkspace($context['workspace']->id));
        $this->assertNull(CloudApiClient::forPhoneNumber('300', $context['workspace']->id));
        $phone->update(['connection_mode' => 'cloud_api', 'coexistence_meta' => null]);
        $this->assertNotNull(CloudApiClient::forWorkspace($context['workspace']->id));
        $this->assertNull(CloudApiClient::forPhoneNumber('300', $context['workspace']->id + 1000));
        $waba->update(['status' => 'inactive']);
        $this->assertNull(CloudApiClient::forWorkspace($context['workspace']->id));
    }
}
